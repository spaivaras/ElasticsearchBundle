<?php

/*
 * This file is part of the ONGR package.
 *
 * (c) NFQ Technologies UAB <info@nfq.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ONGR\ElasticsearchBundle\Tests\Functional\Command;

use ONGR\ElasticsearchBundle\Command\IndexCreateCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class CreateIndexCommandTest extends AbstractCommandTestCase
{
    /**
     * Execution data provider.
     *
     * @return array
     */
    public function getTestExecuteData()
    {
        return [
            [
                'foo',
                [
                    'timestamp' => false,
                    'noMapping' => true,
                ],
            ],
            [
                'default',
                [
                    'timestamp' => false,
                    'noMapping' => false,
                ],
            ],
        ];
    }

    /**
     * Tests creating index. Configuration from tests yaml.
     *
     * @param string $argument
     * @param array  $options
     *
     * @dataProvider getTestExecuteData
     */
    public function testExecute($argument, $options)
    {
        $manager = $this->getManager($argument);

        if ($manager->indexExists()) {
            $manager->dropIndex();
        }

        $app = new Application();
        $app->add($this->getCreateCommand());

        // Creates index.
        $command = $app->find('ongr:es:index:create');
        $commandTester = new CommandTester($command);
        $arguments = [
            'command' => $command->getName(),
            '--manager' => $argument,
        ];
        if ($options['timestamp']) {
            $arguments['--time'] = null;
        }
        if ($options['noMapping']) {
            $arguments['--no-mapping'] = null;
        }
        $commandTester->execute($arguments);
        $this->assertTrue($manager->indexExists(), 'Index should exist.');
        $manager->dropIndex();
    }

    /**
     * Tests if right exception is thrown when manager is read only.
     *
     * @expectedException \Elasticsearch\Common\Exceptions\Forbidden403Exception
     * @expectedExceptionMessage Manager is readonly! Create index operation is not permitted.
     */
    public function testCreateIndexWhenManagerIsReadOnly()
    {
        $manager = $this->getContainer()->get('es.manager.readonly');
        $manager->createIndex();
    }

    /**
     * Returns create index command with assigned container.
     *
     * @return IndexCreateCommand
     */
    protected function getCreateCommand()
    {
        $command = new IndexCreateCommand();
        $command->setContainer($this->getContainer());

        return $command;
    }
}
