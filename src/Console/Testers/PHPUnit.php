<?php

declare(strict_types=1);

namespace ParaTest\Console\Testers;

use InvalidArgumentException;
use ParaTest\Runners\PHPUnit\Options;
use ParaTest\Runners\PHPUnit\Runner;
use ParaTest\Runners\PHPUnit\RunnerInterface;
use ParaTest\Util\Str;
use PHPUnit\TextUI\XmlConfiguration\Configuration;
use PHPUnit\TextUI\XmlConfiguration\Loader;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function array_key_exists;
use function array_merge;
use function class_exists;
use function file_exists;
use function is_string;
use function is_subclass_of;
use function realpath;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;

/**
 * Creates the interface for PHPUnit testing
 */
final class PHPUnit extends Tester
{
    /**
     * @see \PHPUnit\Util\Configuration
     * @see https://github.com/sebastianbergmann/phpunit/commit/80754cf323fe96003a2567f5e57404fddecff3bf
     */
    private const TEST_SUITE_FILTER_SEPARATOR = ',';

    /** @var Command */
    private $command;

    /**
     * Configures the ParaTestCommand with PHPUnit specific
     * definitions.
     */
    public function configure(Command $command): void
    {
        $command
            ->addOption(
                'phpunit',
                null,
                InputOption::VALUE_REQUIRED,
                'The PHPUnit binary to execute. <comment>(default: vendor/bin/phpunit)</comment>'
            )
            ->addOption(
                'runner',
                null,
                InputOption::VALUE_REQUIRED,
                'Runner, WrapperRunner or SqliteRunner. <comment>(default: Runner)</comment>'
            )
            ->addOption('bootstrap', null, InputOption::VALUE_REQUIRED, 'The bootstrap file to be used by PHPUnit.')
            ->addOption('configuration', 'c', InputOption::VALUE_REQUIRED, 'The PHPUnit configuration file to use.')
            ->addOption('group', 'g', InputOption::VALUE_REQUIRED, 'Only runs tests from the specified group(s).')
            ->addOption(
                'exclude-group',
                null,
                InputOption::VALUE_REQUIRED,
                'Don\'t run tests from the specified group(s).'
            )
            ->addOption(
                'stop-on-failure',
                null,
                InputOption::VALUE_NONE,
                'Don\'t start any more processes after a failure.'
            )
            ->addOption(
                'log-junit',
                null,
                InputOption::VALUE_REQUIRED,
                'Log test execution in JUnit XML format to file.'
            )
            ->addOption('colors', null, InputOption::VALUE_NONE, 'Displays a colored bar as a test result.')
            ->addOption('testsuite', null, InputOption::VALUE_OPTIONAL, 'Filter which testsuite to run')
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'The path to a directory or file containing tests. <comment>(default: current directory)</comment>'
            )
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'An alias for the path argument.');
        $this->command = $command;
    }

    /**
     * Executes the PHPUnit Runner. Will Display help if no config and no path
     * supplied.
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if (! $this->hasConfig($input) && ! $this->hasPath($input)) {
            return $this->displayHelp($input, $output);
        }

        $options     = new Options($this->getRunnerOptions($input));
        $runnerClass = $this->getRunnerClass($input);

        $runner = new $runnerClass($options, $output);
        $runner->run();

        return $runner->getExitCode();
    }

    /**
     * Returns whether or not a test path has been supplied
     * via option or regular input.
     */
    private function hasPath(InputInterface $input): bool
    {
        $argument = $input->getArgument('path');
        $option   = $input->getOption('path');

        return ($argument !== null && $argument !== '')
            || ($option !== null && $option !== '');
    }

    /**
     * Is there a PHPUnit xml configuration present.
     */
    private function hasConfig(InputInterface $input): bool
    {
        return $this->getConfig($input) !== null;
    }

    private function getConfig(InputInterface $input): ?Configuration
    {
        if (is_string($path = $input->getOption('configuration')) && file_exists($path)) {
            $configFilename = $path;
        } elseif (file_exists($path = 'phpunit.xml')) {
            $configFilename = $path;
        } elseif (file_exists($path = 'phpunit.xml.dist')) {
            $configFilename = $path;
        } else {
            return null;
        }

        return (new Loader())->load(realpath($configFilename));
    }

    /**
     * Displays help for the ParaTestCommand.
     */
    private function displayHelp(InputInterface $input, OutputInterface $output): int
    {
        $help  = $this->command->getApplication()->find('help');
        $input = new ArrayInput(['command_name' => 'paratest']);

        return $help->run($input, $output);
    }

    /**
     * @return array<string, string|string[]>
     *
     * @throws RuntimeException
     */
    public function getRunnerOptions(InputInterface $input): array
    {
        $path    = $input->getArgument('path');
        $options = $this->getOptions($input);

        if ($this->hasCoverage($options)) {
            $options['coverage-php'] = tempnam(sys_get_temp_dir(), 'paratest_');
        }

        if ($path !== null && $path !== '') {
            $options = array_merge(['path' => $path], $options);
        }

        if (array_key_exists('testsuite', $options)) {
            $options['testsuite'] = Str::explodeWithCleanup(
                self::TEST_SUITE_FILTER_SEPARATOR,
                $options['testsuite']
            );
        }

        return $options;
    }

    /**
     * Return whether or not code coverage information should be collected.
     *
     * @param array<string, string> $options
     */
    private function hasCoverage(array $options): bool
    {
        $isFileFormat = isset($options['coverage-html'])
            || isset($options['coverage-clover'])
            || isset($options['coverage-crap4j'])
            || isset($options['coverage-xml']);
        $isTextFormat = isset($options['coverage-text']);
        $isPHP        = isset($options['coverage-php']);

        return $isTextFormat || $isFileFormat && ! $isPHP;
    }

    /**
     * @return class-string<RunnerInterface>
     */
    private function getRunnerClass(InputInterface $input): string
    {
        $runnerClass = Runner::class;
        $runner      = $input->getOption('runner');
        if ($runner !== null) {
            $runnerClass = $runner;
            $runnerClass = class_exists($runnerClass)
                ? $runnerClass
                : '\\ParaTest\\Runners\\PHPUnit\\' . $runnerClass;
        }

        if (! class_exists($runnerClass) || ! is_subclass_of($runnerClass, RunnerInterface::class)) {
            throw new InvalidArgumentException(sprintf(
                'Selected runner class "%s" does not exist or does not implement %s',
                $runnerClass,
                RunnerInterface::class
            ));
        }

        return $runnerClass;
    }
}
