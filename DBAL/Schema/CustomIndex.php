<?php

namespace Intaro\CustomIndexBundle\DBAL\Schema;

use Symfony\Component\Validator\Mapping\ClassMetadata;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\DBAL\Connection;

class CustomIndex
{
    const PLATFROM_POSTGRESQL = 'postgresql';

    const PREFIX = 'i_cindex_';

    const UNIQUE = 'unique';

    protected static $availableUsingMethods = ['btree', 'hash', 'gin', 'gist'];

    protected static $currentSchema;

    protected $name;

    protected $columns = [];

    protected $unique;

    protected $using;

    protected $where;

    protected $tableName;

    protected $schema;

    /**
     * validation
     *
     * @param ClassMetadata
     **/
    public static function loadValidatorMetadata(ClassMetadata $metadata)
    {
        $metadata->addPropertyConstraint('tableName', new Assert\NotBlank());
        $metadata->addPropertyConstraint('tableName', new Assert\Length([
            'min'           => 1,
            'minMessage'    => 'TableName must be set',
            'max'           => 63,
            'maxMessage'    => 'TableName is too long',
        ]));
        $metadata->addPropertyConstraint('name', new Assert\Length([
            'min'           => 1,
            'minMessage'    => 'Name must be set',
            'max'           => 63,
            'maxMessage'    => 'Name is too long',
        ]));
        $metadata->addPropertyConstraint('using', new Assert\Choice([
            'choices'       => self::$availableUsingMethods,
        ]));
        $metadata->addPropertyConstraint('columns', new Assert\Count([
            'min'           => 1,
            'minMessage'    => "You must specify at least one column",
        ]));
        $metadata->addPropertyConstraint('columns', new Assert\All([
            'constraints'   => [
                new Assert\NotBlank(),
                new Assert\Type([
                    'type' => 'string',
                    'message' => 'Column should be type of string',
                ]),
                new Assert\Length([ 'min' => 1 ]),
            ],
        ]));
    }

    /**
     * drop index
     *
     * @param Connection $con
     *
     * @return bool
     **/
    public static function drop(Connection $con, $indexId)
    {
        $platform = $con->getDatabasePlatform()
            ->getName();

        $sql = self::getDropIndexSql($platform, $indexId);

        $st = $con->prepare($sql);
        return $st->executeQuery();
    }

    /**
     * sql for index delete
     *
     * @param $platform
     * @param $indexName - index name in db
     *
     * @return string - sql
     **/
    public static function getDropIndexSql($platform, $indexName)
    {
        $sql = '';
        switch($platform) {
            case self::PLATFROM_POSTGRESQL:
                $sql = 'DROP INDEX ' . $indexName;
                break;
            default:
                self::unsupportedPlatform($platform);
        }
        return $sql;
    }

    /**
     * Get default schema name and store it in static property
     *
     * @param Connection $con
     * @return string
     */
    public static function getCurrentSchema(Connection $con)
    {
        if (!isset(self::$currentSchema)) {
            $platform = $con->getDatabasePlatform()
                ->getName();

            $sql = self::getCurrentSchemaSql($platform);
            $statement = $con->prepare($sql);
            $results = $statement->executeQuery();
            $result = $results->fetchAssociative();

            self::$currentSchema = isset($result['current_schema']) ?
                $result['current_schema'] : null;
        }

        return self::$currentSchema;
    }

    /**
     * sql for select current schema
     *
     * @param $platform
     *
     * @return string - sql
     **/
    public static function getCurrentSchemaSql($platform)
    {
        $sql = '';
        switch($platform) {
            case self::PLATFROM_POSTGRESQL:
                $sql = 'SELECT current_schema()';
                break;
            default:
                self::unsupportedPlatform($platform);
        }

        return $sql;
    }


    public static function getCurrentIndex(Connection $con, $searchInAllSchemas)
    {
        $platform = $con->getDatabasePlatform()
            ->getName();

        $sql = self::getCurrentIndexSql($platform, $searchInAllSchemas);
        $statement = $con->prepare($sql);
        $value = self::PREFIX . '%';
        $statement->bindParam(1,$value);
        $results = $statement->executeQuery();
        $result = [];
        if ($data = $results->fetchallAssociative()) {
            foreach ($data as $row) {
                $result[] = $row['relname'];
            }
        }

        return $result;
    }

    /**
     * Return sql for select current index
     *
     * @param $platform
     * @param $searchInAllSchemas
     * @return string
     */
    public static function getCurrentIndexSql($platform, $searchInAllSchemas)
    {
        switch($platform) {
            case self::PLATFROM_POSTGRESQL:
                $sql = "
                    SELECT schemaname || '.' || indexname as relname FROM pg_indexes
                    WHERE indexname LIKE ?
                ";
                if (!$searchInAllSchemas) {
                    $sql .= " AND schemaname = current_schema()";
                }
                break;
            default:
                self::unsupportedPlatform($platform);

        }

        return $sql;
    }

    public static function unsupportedPlatform($platform)
    {
        throw new \LogicException("Platform {$platform} does not support");
    }

    public function __construct(
        $tableName,
        $columns,
        $name = null,
        $unique = false,
        $using = null,
        $where = null,
        $schema = null
    ) {
        $vars = [
            'tableName',
            'columns',
            'unique',
            'using',
            'where',
            'schema'
        ];
        foreach ($vars as $var) {
            $method = 'set' . ucfirst($var);
            $this->$method($$var);
        }

        if ($name) {
            $this->setName($name);
        } else {
            $this->generateName();
        }
    }

    /**
     * create index
     *
     * @param Connection $con
     *
     * @return bool
     **/
    public function create(Connection $con)
    {
        $platform = $con->getDatabasePlatform()
            ->getName();

        $sql = $this->getCreateIndexSql($platform);
        $st = $con->prepare($sql);
        $result = $st->executeQuery();

        return $result;
    }

    /**
     * sql fo index create
     *
     * @param string $platform
     *
     * @return string - sql
     **/
    public function getCreateIndexSql($platform)
    {
        $sql = '';
        switch($platform) {
            case self::PLATFROM_POSTGRESQL:
                $sql = 'CREATE ';
                if ($this->getUnique()) {
                    $sql .= 'UNIQUE ';
                }

                $sql .= 'INDEX ' .  $this->getName() . ' ';
                $sql .= 'ON ' . $this->getTableName() . ' ';

                if ($this->getUsing()) {
                    $sql .= 'USING ' . $this->getUsing() . ' ';
                }

                $sql .= '(' . implode(', ', $this->getColumns()) . ')';

                if ($this->getWhere()) {
                    $sql .= ' WHERE ' . $this->getWhere();
                }
                break;
            default:
                self::unsupportedPlatform($platform);
        }

        return $sql;
    }

    /**
     * generate index name
     *
     * @return string - index name
     **/
    public function generateName()
    {
        $columns = $this->getColumns();
        $strToMd5 = $this->getTableName();

        foreach ($columns as $column) {
            $strToMd5 .= $column;
        }

        $strToMd5 .= (string) $this->getUsing() . ($this->getWhere() ? '_' . (string) $this->getWhere() : '');
        $name = self::PREFIX
            . ( $this->getUnique() ? self::UNIQUE . '_' : '' )
            . md5($strToMd5);

        $this->setName($name);

        return $name;
    }

    public function setTableName($tableName)
    {
        $this->tableName = $tableName;

        return $this;
    }

    public function getTableName()
    {
        if (
            !empty($this->getSchema())
            && $this->getSchema() != self::$currentSchema
        ) {
            return $this->getSchema() . '.' . $this->tableName;
        } else {
            return $this->tableName;
        }
    }

    public function setSchema($schema)
    {
        $this->schema = $schema;

        return $this;
    }

    public function getSchema()
    {
        return $this->schema;
    }

    public function setName($name)
    {
        if (strpos($name, self::PREFIX) !== 0) {
            $name = self::PREFIX . $name;
        }

        $this->name = $name;

        return $this;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setColumns($columns)
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }

        $this->columns = [];
        foreach ($columns as $column) {
            if (!empty($column)) {
                $this->columns[] = $column;
            }
        }

        return $this;
    }

    public function getColumns()
    {
        return $this->columns;
    }

    public function setUnique($unique)
    {
        $this->unique = (bool) $unique;

        return $this;
    }

    public function getUnique()
    {
        return $this->unique;
    }

    public function setUsing($using)
    {
        $this->using = $using;

        return $this;
    }

    public function getUsing()
    {
        return $this->using;
    }

    public function setWhere($where)
    {
        $this->where = (string) $where;

        return $this;
    }

    public function getWhere()
    {
        return $this->where;
    }
}
