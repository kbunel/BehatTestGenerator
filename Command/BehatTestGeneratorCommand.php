<?php

namespace BehatTestGenerator\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use BehatTestGenerator\Services\BehatTestGenerator;

class BehatTestGeneratorCommand extends ContainerAwareCommand
{
    protected static $defaultName = 'kbunel:behat:generate-test';

    private $testGenerator;

    public function __construct(BehatTestGenerator $testGenerator)
    {
        $this->testGenerator = $testGenerator;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Generate test for controller\'s route.')
            ->addOption('namespace', null, InputOption::VALUE_OPTIONAL, 'Restrict test generated to a specified namespace.')
            ->addOption('tag', null, InputOption::VALUE_OPTIONAL, 'Add a tag to the generated test.')
            ->addOption('methods', null, InputOption::VALUE_OPTIONAL, 'Restrict test generated to some methods, separated by a comma.')
            ->addOption('fromNamespace', null, InputOption::VALUE_OPTIONAL, 'Restrict test where namespaces start from a specific namespace.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $namespace = $input->getOption('namespace');
        $methods = $this->getMethods($input->getOption('methods'));
        $tag = $input->getOption('tag');
        $fromNamespace = $input->getOption('fromNamespace');

        $this->testGenerator->generate($namespace, $methods, $tag, $fromNamespace, $output->isVerbose());
    }

    private function getMethods(?string $methods = null): ?array
    {
        if (!$methods) {
            return null;
        }

        $m = [];
        foreach (explode(',', str_replace(' ', '', $methods)) as $method) {
            $m[] = $method;
        }

        return $m;
    }
}
