<?php

declare(strict_types=1);

namespace ParaTest\Tests\Functional;

final class GroupTest extends FunctionalTestBase
{
    /** @var ParaTestInvoker */
    private $invoker;

    public function setUp(): void
    {
        parent::setUp();
        $this->invoker = new ParaTestInvoker($this->fixture('passing-tests/GroupsTest.php'));
    }

    public function testGroupSwitchOnlyExecutesThoseGroups(): void
    {
        $proc = $this->invoker->execute(['group' => 'group1']);
        static::assertMatchesRegularExpression('/OK \(2 tests, 2 assertions\)/', $proc->getOutput());
    }

    public function testExcludeGroupSwitchDontExecuteThatGroup(): void
    {
        $proc = $this->invoker->execute(['exclude-group' => 'group1']);

        static::assertMatchesRegularExpression('/OK \(3 tests, 3 assertions\)/', $proc->getOutput());
    }

    public function testGroupSwitchOnlyExecutesThoseGroupsInFunctionalMode(): void
    {
        $proc = $this->invoker->execute(['functional' => true, 'group' => 'group1']);
        static::assertMatchesRegularExpression('/OK \(2 tests, 2 assertions\)/', $proc->getOutput());
    }

    public function testGroupSwitchOnlyExecutesThoseGroupsWhereTestHasMultipleGroups(): void
    {
        $proc = $this->invoker->execute(['functional' => true, 'group' => 'group3']);
        static::assertMatchesRegularExpression('/OK \(1 test, 1 assertion\)/', $proc->getOutput());
    }

    public function testGroupsSwitchExecutesMultipleGroups(): void
    {
        $proc = $this->invoker->execute(['functional' => true, 'group' => 'group1,group3']);
        static::assertMatchesRegularExpression('/OK \(3 tests, 3 assertions\)/', $proc->getOutput());
    }
}
