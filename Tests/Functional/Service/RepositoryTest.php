<?php

/*
 * This file is part of the ONGR package.
 *
 * (c) NFQ Technologies UAB <info@nfq.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ONGR\ElasticsearchBundle\Tests\Functional;

use ONGR\ElasticsearchBundle\Document\DocumentInterface;
use ONGR\ElasticsearchBundle\Tests\app\fixture\Acme\BarBundle\Document\ProductDocument;
use ONGR\ElasticsearchDSL\Filter\MissingFilter;
use ONGR\ElasticsearchDSL\Filter\PrefixFilter;
use ONGR\ElasticsearchDSL\Query\MatchAllQuery;
use ONGR\ElasticsearchDSL\Query\RangeQuery;
use ONGR\ElasticsearchDSL\Query\TermQuery;
use ONGR\ElasticsearchDSL\Search;
use ONGR\ElasticsearchBundle\Service\Manager;
use ONGR\ElasticsearchBundle\Service\Repository;
use ONGR\ElasticsearchBundle\Test\AbstractElasticsearchTestCase;

class RepositoryTest extends AbstractElasticsearchTestCase
{
    /**
     * {@inheritdoc}
     */
    protected function getDataArray()
    {
        return [
            'default' => [
                'product' => [
                    [
                        '_id' => 1,
                        'title' => 'foo',
                        'price' => 10,
                        'description' => 'goo Lorem',
                    ],
                    [
                        '_id' => 2,
                        'title' => 'bar',
                        'price' => 1000,
                        'description' => 'foo bar Lorem adips distributed disributed',
                    ],
                    [
                        '_id' => 3,
                        'title' => 'gar',
                        'price' => 100,
                        'description' => 'foo bar Loremo',
                    ],
                    [
                        '_id' => 4,
                        'title' => 'tuna',
                        'description' => 'tuna bar Loremo Batman',
                    ],
                ],
            ],
        ];
    }

    /**
     * Data provider for test find by.
     *
     * @return array
     */
    public function getFindByData()
    {
        $out = [];

        // Case #0 simple find by title.
        $out[] = [
            [1],
            ['title' => 'foo'],
        ];

        // Case #1 find by multiple titles.
        $out[] = [
            [1, 2],
            [
                'title' => [
                    'foo',
                    'bar',
                ],
            ],
        ];

        // Case #2 find by multiple titles and simple sort.
        $out[] = [
            [2, 1],
            [
                'title' => [
                    'foo',
                    'bar',
                ],
            ],
            ['title' => 'asc'],
        ];

        // Case #3 find by multiple titles and multiple sorts.
        $criteria = [
            'description' => [
                'foo',
                'goo',
            ],
            'title' => [
                'foo',
                'bar',
                'gar',
            ],
        ];
        $out[] = [
            [2, 3, 1],
            $criteria,
            [
                'description' => 'ASC',
                'price' => 'DESC',
            ],
        ];

        // Case #4 offset.
        $out[] = [
            [3, 1],
            $criteria,
            [
                'description' => 'ASC',
                'price' => 'DESC',
            ],
            null,
            1,
        ];

        // Case #5 limit.
        $out[] = [
            [2, 3],
            $criteria,
            [
                'description' => 'ASC',
                'price' => 'DESC',
            ],
            2,
        ];

        // Case #6 limit and offset.
        $out[] = [
            [3],
            $criteria,
            [
                'description' => 'ASC',
                'price' => 'DESC',
            ],
            1,
            1,
        ];

        return $out;
    }

    /**
     * Check if find by works as expected.
     *
     * @param array $expectedResults
     * @param array $criteria
     * @param array $orderBy
     * @param int   $limit
     * @param int   $offset
     *
     * @dataProvider getFindByData()
     */
    public function testFindBy($expectedResults, $criteria, $orderBy = [], $limit = null, $offset = null)
    {
        $repo = $this->getManager()->getRepository('AcmeBarBundle:ProductDocument');

        $fullResults = $repo->findBy($criteria, $orderBy, $limit, $offset);

        $results = [];

        /** @var DocumentInterface $result */
        foreach ($fullResults as $result) {
            $results[] = $result->getId();
        }

        // Results are not sorted, they will be returned in random order.
        if (empty($orderBy)) {
            sort($results);
            sort($expectedResults);
        }

        $this->assertEquals($expectedResults, $results);
    }

    /**
     * Data provider for test find one by.
     *
     * @return array
     */
    public function getFindOneByData()
    {
        $out = [];

        // Case #0 find one by title for not existed.
        $out[] = [
            null,
            ['title' => 'baz'],
        ];

        // Case #1 simple find one by title.
        $out[] = [
            1,
            ['title' => 'foo'],
        ];

        // Case #2 find one by multiple titles and simple sort.
        $out[] = [
            2,
            [
                'title' => [
                    'foo',
                    'bar',
                ],
            ],
            ['title' => 'asc'],
        ];

        // Case #3 find one by multiple titles and multiple sorts.
        $criteria = [
            'description' => [
                'foo',
                'goo',
            ],
            'title' => [
                'foo',
                'bar',
                'gar',
            ],
        ];
        $out[] = [
            2,
            $criteria,
            [
                'description' => 'ASC',
                'price' => 'DESC',
            ],
        ];

        return $out;
    }

    /**
     * Check if find one by works as expected.
     *
     * @param int|null $expectedResult
     * @param array    $criteria
     * @param array    $orderBy
     *
     * @dataProvider getFindOneByData()
     */
    public function testFindOneBy($expectedResult, $criteria, $orderBy = [])
    {
        $repo = $this->getManager()->getRepository('AcmeBarBundle:ProductDocument');

        $result = $repo->findOneBy($criteria, $orderBy);

        if ($expectedResult === null) {
            $this->assertNull($result);
        } else {
            $this->assertNotNull($result);
            $this->assertEquals($expectedResult, $result->getId());
        }
    }

    /**
     * Test repository find method with array result type.
     */
    public function testFind()
    {
        $manager = $this->getManager();

        $product = new ProductDocument;
        $product->setId('123');
        $product->title = 'foo';

        $manager->persist($product);
        $manager->commit();

        $repo = $manager->getRepository('AcmeBarBundle:ProductDocument');

        $result = $repo->find(123);

        $this->assertEquals(get_object_vars($product), get_object_vars($result));
    }

    /**
     * Test repository find on non-existent document.
     */
    public function testFindNull()
    {
        $repo = $this->getManager()->getRepository('AcmeBarBundle:ProductDocument');

        $this->assertNull($repo->find(123));
    }

    /**
     * Test repository find method in repo with many types.
     *
     * @expectedException \LogicException
     */
    public function testFindInMultiTypeRepo()
    {
        /** @var Repository $repo */
        $repo = $this->getManager()->getRepository(['AcmeBarBundle:ProductDocument', 'AcmeFooBundle:CustomerDocument']);

        $repo->find(1);
    }

    /**
     * Tests remove method.
     */
    public function testRemove()
    {
        $manager = $this->getManager();

        $repo = $manager->getRepository('AcmeBarBundle:ProductDocument');

        $response = $repo->remove(3);

        $this->assertArrayHasKey('found', $response);
        $this->assertEquals(1, $response['found']);
    }

    /**
     * Tests remove method 404 exception.
     *
     * @expectedException \Elasticsearch\Common\Exceptions\Missing404Exception
     */
    public function testRemoveException()
    {
        $manager = $this->getManager();

        $repo = $manager->getRepository('AcmeBarBundle:ProductDocument');

        $repo->remove(500);
    }

    /**
     * Test parseResult when 0 documents found using execute.
     */
    public function testRepositoryExecuteWhenZeroResult()
    {
        $repository = $this->getManager()->getRepository('AcmeBarBundle:ProductDocument');

        $search = $repository
            ->createSearch()
            ->addFilter(new PrefixFilter('title', 'dummy'));

        $searchResult = $repository->execute($search, Repository::RESULTS_OBJECT);
        $this->assertInstanceOf(
            '\ONGR\ElasticsearchBundle\Result\DocumentIterator',
            $searchResult
        );
        $this->assertCount(0, $searchResult);
    }

    /**
     * @return array
     */
    protected function getProductsArray()
    {
        return $this->getDataArray()['default']['product'];
    }

    /**
     * Tests if document is being updated when persisted.
     */
    public function testDocumentUpdate()
    {
        $manager = $this->getManager();
        $repository = $manager->getRepository('AcmeBarBundle:ProductDocument');

        $document = new ProductDocument;

        $document->setId(5);
        $document->title = 'acme';

        $manager->persist($document);
        $manager->commit();

        // Creates document.
        /** @var ProductDocument $document */
        $document = $repository->find(5);
        $this->assertEquals(
            [
                'id' => '5',
                'title' => 'acme',
            ],
            [
                'id' => $document->getId(),
                'title' => $document->title,
            ],
            'Document should be created.'
        );

        $document->title = 'acme bar';

        // Updates document.
        $manager->persist($document);
        $manager->commit();

        $document = $repository->find(5);
        $this->assertEquals(
            [
                'id' => '5',
                'title' => 'acme bar',
            ],
            [
                'id' => $document->getId(),
                'title' => $document->title,
            ],
            'Document should be updated.'
        );
    }

    /**
     * Tests if repository returns same manager as it was original.
     */
    public function testGetManager()
    {
        $manager = $this->getManager();
        $repository = $manager->getRepository('AcmeBarBundle:ProductDocument');
        $this->assertEquals($manager, $repository->getManager());
    }

    /**
     * Tests if documents are deleted by query.
     */
    public function testDeleteByQuery()
    {
        /** @var Manager $manager */
        $all = new MatchAllQuery();
        $manager = $this->getManager();
        $index = $manager->getIndexName();
        $repository = $manager->getRepository('AcmeBarBundle:ProductDocument');
        $search = $repository->createSearch()->addQuery($all);
        $results = $repository->execute($search)->count();
        $this->assertEquals(4, $results);

        $query = $repository->createSearch();
        $term = new RangeQuery('price', ['gt' => 1, 'lt' => 200]);
        $query->addQuery($term);

        $expectedResults = [
            'failed' => 0,
            'successful' => 5,
            'total' => 5,
        ];

        $result = $repository->deleteByQuery($query);
        $this->assertEquals($expectedResults['failed'], $result['_indices'][$index]['_shards']['failed']);
        $this->assertEquals($expectedResults['successful'], $result['_indices'][$index]['_shards']['successful']);
        $this->assertEquals($expectedResults['total'], $result['_indices'][$index]['_shards']['total']);

        $search = $repository->createSearch()->addQuery($all);
        $results = $repository->execute($search)->count();
        $this->assertEquals(2, $results);
    }

    /**
     * Tests if find works as expected with RESULTS_RAW return type.
     */
    public function testFindArrayRaw()
    {
        $manager = $this->getManager();
        $index = $manager->getIndexName();
        $repository = $manager->getRepository('AcmeBarBundle:ProductDocument');
        $document = $repository->find(1, Repository::RESULTS_RAW);
        $expected = [
            '_index' => $index,
            '_id' => 1,
            '_type' => 'product',
            '_version' => 1,
            'found' => true,
            '_source' => [
                'title' => 'foo',
                'price' => 10,
                'description' => 'goo Lorem',
            ],
        ];
        $this->assertEquals(asort($expected), asort($document));
    }

    /**
     * Tests if find works as expected with RESULTS_ARRAY return type.
     */
    public function testFindArray()
    {
        $manager = $this->getManager();
        $repository = $manager->getRepository('AcmeBarBundle:ProductDocument');
        $document = $repository->find(1, Repository::RESULTS_ARRAY);
        $expected = [
            'title' => 'foo',
            'price' => 10,
            'description' => 'goo Lorem',
        ];
        $this->assertEquals($expected, $document);
    }

    /**
     * Tests if find works as expected with RESULTS_RAW_ITERATOR return type.
     */
    public function testFindArrayIterator()
    {
        $manager = $this->getManager();
        $repository = $manager->getRepository('AcmeBarBundle:ProductDocument');
        $document = $repository->find(1, Repository::RESULTS_RAW_ITERATOR);
        $this->assertInstanceOf('ONGR\ElasticsearchBundle\Result\RawIterator', $document);
    }
}
