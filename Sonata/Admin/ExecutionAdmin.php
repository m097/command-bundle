<?php

namespace Draw\Bundle\CommandBundle\Sonata\Admin;

use Draw\Bundle\CommandBundle\CommandRegistry;
use Draw\Bundle\CommandBundle\Entity\Execution;
use Draw\Bundle\CommandBundle\Listener\CommandFlowListener;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\RouteCollection;
use Sonata\AdminBundle\Route\RouteCollectionInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\DoctrineORMAdminBundle\Filter\ChoiceFilter;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

class ExecutionAdmin extends AbstractAdmin
{
    /**
     * @var CommandRegistry
     */
    private $commandFactory;

    /**
     * @var KernelInterface
     */
    private $kernel;

    /**
     * @required
     */
    public function inject(CommandRegistry $commandFactory, KernelInterface $kernel)
    {
        $this->kernel = $kernel;
        $this->commandFactory = $commandFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function createNewInstance(): object
    {
        /** @var Execution $execution */
        $execution = parent::createNewInstance();

        if ($this->hasRequest() && $this->getRequest()->isMethod(Request::METHOD_GET)) {
            if ($commandName = $this->getRequest()->get('command')) {
                $command = $this->commandFactory->getCommand($commandName);
                $execution->setCommand($command->getName());
                $execution->setCommandName($command->getCommandName());
            }
        }

        return $execution;
    }

    protected function configureDefaultSortValues(array &$sortValues): void
    {
        $sortValues['_sort_order'] = 'DESC';
        $sortValues['_sort_by'] = 'id';
    }

    protected function configureDatagridFilters(DatagridMapper $filter): void
    {
        $filter
            ->add('id')
            ->add('command')
            ->add('commandName')
            ->add(
                'state',
                ChoiceFilter::class,
                [
                    'field_type' => ChoiceType::class,
                    'field_options' => [
                        'choices' => array_combine(
                            Execution::STATES,
                            Execution::STATES
                        ),
                    ],
                ]
            )
            ->add('output')
            ->add('createdAt');
    }

    protected function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('id')
            ->add('command')
            ->add('commandName')
            ->add('state')
            ->add('createdAt');
    }

    protected function configureShowFields(ShowMapper $show): void
    {
        $show
            ->tab('Execution')
                ->with('General')
                    ->add('id')
                    ->add('command')
                    ->add('commandName')
                    ->add('state')
                    ->add('createdAt')
                    ->add('updatedAt')
                    ->add('input', 'array')
                ->end()
                ->with('Execution')
                    ->add('commandLine', 'text')
                    ->add('outputHtml', 'html')
                ->end()
            ->end();
    }

    protected function configureFormFields(FormMapper $form): void
    {
        $form
            ->add('command', null, ['attr' => ['readonly' => true]])
            ->add('commandName', null, ['attr' => ['readonly' => true]]);
    }

    /**
     * @param RouteCollection|RouteCollectionInterface $collection
     */
    protected function backwardCompatibleConfigureRoute($collection)
    {
        if (!\count($this->commandFactory->getCommands())) {
            $collection->remove('create');
        } else {
            $collection
                ->get('create')
                ->setDefault('_controller', $collection->getBaseControllerName().'::myCreateAction');
        }

        $collection->remove('edit');
        $collection->add('acknowledge', $this->getRouterIdParameter().'/acknowledge');
    }

    public function backwardCompatibleConfigureActionButtons(array $buttonList, $action, $object = null): array
    {
        if ('show' == $action && Execution::STATE_ERROR == $object->getState()) {
            $buttonList['acknowledge']['template'] = '@DrawCommand\ExecutionAdmin\button_acknowledge.html.twig';
        }

        return $buttonList;
    }

    /**
     * @param Execution $object
     */
    public function prePersist($object): void
    {
        $object->setState(Execution::STATE_INITIALIZED);
        $object->setInput([
            'command' => $object->getCommandName(),
            '-vvv' => true,
            '--no-interaction' => true,
        ]);
    }

    /**
     * @param Execution $object
     */
    public function postPersist($object): void
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $input = new ArrayInput(
            $object->getInput() + ['--'.CommandFlowListener::OPTION_EXECUTION_ID => $object->getId()]
        );
        $output = new BufferedOutput(OutputInterface::OUTPUT_NORMAL, true);
        $application->run($input, $output);
    }
}
