<?php

/*
 * This file is part of the ONGR package.
 *
 * (c) NFQ Technologies UAB <info@nfq.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ONGR\ElasticsearchBundle\Service;

use Elasticsearch\Common\Exceptions\Missing404Exception;
use ONGR\ElasticsearchBundle\Document\DocumentInterface;
use ONGR\ElasticsearchBundle\Result\AbstractResultsIterator;
use ONGR\ElasticsearchBundle\Result\RawIterator;
use ONGR\ElasticsearchDSL\Query\TermsQuery;
use ONGR\ElasticsearchDSL\Search;
use ONGR\ElasticsearchDSL\Sort\FieldSort;
use ONGR\ElasticsearchBundle\Result\Converter;
use ONGR\ElasticsearchBundle\Result\DocumentIterator;

/**
 * Repository class.
 */
class Repository
{
    const RESULTS_ARRAY = 'array';
    const RESULTS_OBJECT = 'object';
    const RESULTS_RAW = 'raw';
    const RESULTS_RAW_ITERATOR = 'raw_iterator';

    /**
     * @var Manager
     */
    private $manager;

    /**
     * @var array
     */
    private $types = [];

    /**
     * Constructor.
     *
     * @param Manager   $manager
     * @param array     $repositories
     * @param Converter $converter
     */
    public function __construct($manager, array $repositories, $converter)
    {
        $this->manager = $manager;
        $this->types = $this->resolveTypes($repositories);
        $this->converter = $converter;
    }

    /**
     * Resolves elasticsearch types from documents.
     *
     * @param array $repositories
     *
     * @return array
     */
    private function resolveTypes($repositories)
    {
        $types = [];
        foreach ($repositories as $repository) {
            $types[] = $this->getManager()->getMetadataCollector()->getDocumentType($repository);
        }

        return $types;
    }

    /**
     * @return array
     */
    public function getTypes()
    {
        return $this->types;
    }

    /**
     * Returns a single document data by ID or null if document is not found.
     *
     * @param string $id         Document Id to find.
     * @param string $resultType Result type returned.
     *
     * @return DocumentInterface|null
     *
     * @throws \LogicException
     */
    public function find($id, $resultType = self::RESULTS_OBJECT)
    {
        if (count($this->types) !== 1) {
            throw new \LogicException('Only one type must be specified for the find() method');
        }

        $params = [
            'index' => $this->getManager()->getIndexName(),
            'type' => $this->types[0],
            'id' => $id,
        ];

        try {
            $result = $this->getManager()->getClient()->get($params);
        } catch (Missing404Exception $e) {
            return null;
        }

        if ($resultType === self::RESULTS_OBJECT) {
            return $this->converter->convertToDocument($result, $this);
        }

        return $this->parseResult($result, $resultType, '');
    }

    /**
     * Finds entities by a set of criteria.
     *
     * @param array      $criteria   Example: ['group' => ['best', 'worst'], 'job' => 'medic'].
     * @param array|null $orderBy    Example: ['name' => 'ASC', 'surname' => 'DESC'].
     * @param int|null   $limit      Example: 5.
     * @param int|null   $offset     Example: 30.
     * @param string     $resultType Result type returned.
     *
     * @return array|DocumentIterator The objects.
     */
    public function findBy(
        array $criteria,
        array $orderBy = [],
        $limit = null,
        $offset = null,
        $resultType = self::RESULTS_OBJECT
    ) {
        $search = $this->createSearch();

        if ($limit !== null) {
            $search->setSize($limit);
        }
        if ($offset !== null) {
            $search->setFrom($offset);
        }

        foreach ($criteria as $field => $value) {
            $search->addQuery(new TermsQuery($field, is_array($value) ? $value : [$value]), 'must');
        }

        foreach ($orderBy as $field => $direction) {
            $search->addSort(new FieldSort($field, $direction));
        }

        return $this->execute($search, $resultType);
    }

    /**
     * Finds only one entity by a set of criteria.
     *
     * @param array      $criteria   Example: ['group' => ['best', 'worst'], 'job' => 'medic'].
     * @param array|null $orderBy    Example: ['name' => 'ASC', 'surname' => 'DESC'].
     * @param string     $resultType Result type returned.
     *
     * @return DocumentInterface|null The object.
     */
    public function findOneBy(array $criteria, array $orderBy = [], $resultType = self::RESULTS_OBJECT)
    {
        $search = $this->createSearch();
        $search->setSize(1);

        foreach ($criteria as $field => $value) {
            $search->addQuery(new TermsQuery($field, is_array($value) ? $value : [$value]), 'must');
        }

        foreach ($orderBy as $field => $direction) {
            $search->addSort(new FieldSort($field, $direction));
        }

        $result = $this
            ->getManager()
            ->search($this->types, $search->toArray(), $search->getQueryParams());

        if ($resultType === self::RESULTS_OBJECT) {
            $rawData = $result['hits']['hits'];
            if (!count($rawData)) {
                return null;
            }

            return $this->converter->convertToDocument($rawData[0], $this);
        }

        return $this->parseResult($result, $resultType, '');
    }

    /**
     * Returns search instance.
     *
     * @return Search
     */
    public function createSearch()
    {
        return new Search();
    }

    /**
     * Executes given search.
     *
     * @param Search $search
     * @param string $resultsType
     *
     * @return DocumentIterator|RawIterator|array
     *
     * @throws \Exception
     */
    public function execute(Search $search, $resultsType = self::RESULTS_OBJECT)
    {
        $results = $this
            ->getManager()
            ->search($this->types, $search->toArray(), $search->getQueryParams());

        return $this->parseResult($results, $resultsType, $search->getScroll());
    }

    /**
     * Delete by query.
     *
     * @param Search $search
     *
     * @return array
     */
    public function deleteByQuery(Search $search)
    {
        $params = [
            'index' => $this->getManager()->getIndexName(),
            'type' => $this->types,
            'body' => $search->toArray(),
        ];

        return $this
            ->getManager()
            ->getClient()
            ->deleteByQuery($params);
    }

    /**
     * Fetches next set of results.
     *
     * @param string $scrollId
     * @param string $scrollDuration
     * @param string $resultsType
     *
     * @return AbstractResultsIterator
     *
     * @throws \Exception
     */
    public function scroll(
        $scrollId,
        $scrollDuration = '5m',
        $resultsType = self::RESULTS_OBJECT
    ) {
        $results = $this->getManager()->getClient()->scroll(['scroll_id' => $scrollId, 'scroll' => $scrollDuration]);

        return $this->parseResult($results, $resultsType, $scrollDuration);
    }

    /**
     * Removes a single document data by ID.
     *
     * @param string $id Document ID to remove.
     *
     * @return array
     *
     * @throws \LogicException
     */
    public function remove($id)
    {
        if (count($this->types) == 1) {
            $params = [
                'index' => $this->getManager()->getIndexName(),
                'type' => $this->types[0],
                'id' => $id,
            ];

            $response = $this->getManager()->getClient()->delete($params);

            return $response;
        } else {
            throw new \LogicException('Only one type must be specified for the find() method');
        }
    }

    /**
     * Parses raw result.
     *
     * @param array  $raw
     * @param string $resultsType
     * @param string $scrollDuration
     *
     * @return DocumentIterator|RawIterator|array
     *
     * @throws \Exception
     */
    private function parseResult($raw, $resultsType, $scrollDuration = null)
    {
        $scrollConfig = [];
        if (isset($raw['_scroll_id'])) {
            $scrollConfig['_scroll_id'] = $raw['_scroll_id'];
            $scrollConfig['duration'] = $scrollDuration;
        }

        switch ($resultsType) {
            case self::RESULTS_OBJECT:
                return new DocumentIterator($raw, $this, $scrollConfig);
            case self::RESULTS_ARRAY:
                return $this->convertToNormalizedArray($raw);
            case self::RESULTS_RAW:
                return $raw;
            case self::RESULTS_RAW_ITERATOR:
                return new RawIterator($raw, $this, $scrollConfig);
            default:
                throw new \Exception('Wrong results type selected');
        }
    }

    /**
     * Normalizes response array.
     *
     * @param array $data
     *
     * @return array
     */
    private function convertToNormalizedArray($data)
    {
        if (array_key_exists('_source', $data)) {
            return $data['_source'];
        }

        $output = [];

        if (isset($data['hits']['hits'][0]['_source'])) {
            foreach ($data['hits']['hits'] as $item) {
                $output[] = $item['_source'];
            }
        } elseif (isset($data['hits']['hits'][0]['fields'])) {
            foreach ($data['hits']['hits'] as $item) {
                $output[] = array_map('reset', $item['fields']);
            }
        }

        return $output;
    }

    /**
     * Returns elasticsearch manager used in the repository.
     *
     * @return Manager
     */
    public function getManager()
    {
        return $this->manager;
    }
}
