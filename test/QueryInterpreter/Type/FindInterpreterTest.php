<?php

namespace Silktide\Reposition\Sql\Test\QueryInterpreter\Type;

use Silktide\Reposition\Exception\MetadataException;
use Silktide\Reposition\Sql\QueryInterpreter\Type\FindInterpreter;
use Silktide\Reposition\QueryBuilder\TokenSequencerInterface;
use Silktide\Reposition\Metadata\EntityMetadataProviderInterface;

class FindInterpreterTest extends \PHPUnit_Framework_TestCase {

    /**
     * @var EntityMetadataProviderInterface
     */
    protected $metadataProvider;

    public function setUp()
    {
        $this->metadataProvider = \Mockery::mock("Silktide\\Reposition\\Metadata\\EntityMetadataProviderInterface");
    }

    /**
     * @dataProvider simpleTokenSequenceProvider
     *
     * @param array $tokens
     * @param $expectedSql
     */
    public function testInterpretation(array $tokens, $expectedSql)
    {
        $entityClass = "ClassName";

        $metadataConfig = [[
            "class" => $entityClass,
            "collection" => "table_name",
            "fields" => ["string_field", "int_field", "bool_field"],
            "dontInclude" => true
        ]];

        $this->configureMetadataProvider($metadataConfig);

        $tokenSequencer = $this->createMockTokenSequencer($tokens, $entityClass);

        /** @var EntityMetadataProviderInterface  $metadataProvider */
        $interpreter = new FindInterpreter();
        $interpreter->setEntityMetadataProvider($this->metadataProvider);

        /** @var TokenSequencerInterface $tokenSequencer */
        $this->assertEquals($expectedSql, $interpreter->interpretQuery($tokenSequencer));
    }

    protected function configureMetadataProvider(array $metadataConfig)
    {
        $metadataMap = [];
        $includes = [];
        foreach ($metadataConfig as $alias => $config) {
            $entityClass = $config["class"];
            $collection = empty($config["collection"])? $alias: $config["collection"];
            $fields = $config["fields"];
            $primaryKey = empty($config["pk"])? "id": $config["pk"];

            $metadata = \Mockery::mock("Silktide\\Reposition\\Metadata\\EntityMetadata");
            $metadata->shouldReceive("getCollection")->andReturn($collection);
            $metadata->shouldReceive("getFieldNames")->andReturn($fields);
            $metadata->shouldReceive("getPrimaryKey")->andReturn($primaryKey);
            $metadataMap[$entityClass] = $metadata;
            if (empty($config["dontInclude"])) {
                $includes[$alias] = $entityClass;
            }
        }
        $this->metadataProvider->shouldReceive("getEntityMetadata")->andReturnUsing(function($entity) use ($metadataMap) {
            if (empty($metadataMap[$entity])) {
                throw new MetadataException("Test: Could not find metadata for the entity '$entity'");
            }
            return $metadataMap[$entity];
        });
        return $includes;
    }

    protected function createMockTokenSequencer(array $tokens, $entityClass, array $includes = [])
    {
        $sequence = [];
        foreach ($tokens as $tokenArgs) {
            $token = \Mockery::mock("Silktide\\Reposition\\QueryBuilder\\QueryToken\\" . ucfirst($tokenArgs[0]));
            if (isset($tokenArgs[1])) {
                $token->shouldReceive("getType")->andReturn($tokenArgs[1]);
            }
            if (isset($tokenArgs[2])) {
                $token->shouldReceive("getValue")->andReturn($tokenArgs[2]);
            }
            if (isset($tokenArgs[3])) {
                $token->shouldReceive("getAlias")->andReturn($tokenArgs[3]);
            }
            $sequence[] = $token;
        }
        $sequence[] = false;


        $tokenSequencer = \Mockery::mock("Silktide\\Reposition\\QueryBuilder\\TokenSequencerInterface");
        $tokenSequencer->shouldReceive("getNextToken")->andReturnValues($sequence);
        $tokenSequencer->shouldReceive("getEntityName")->andReturn($entityClass);
        $tokenSequencer->shouldReceive("getIncludes")->andReturn($includes);

        return $tokenSequencer;
    }

    public function simpleTokenSequenceProvider()
    {
        $defaultSql = "SELECT " .
                      "`table_name`.`string_field` AS `table_name__string_field`, " .
                      "`table_name`.`int_field` AS `table_name__int_field`, " .
                      "`table_name`.`bool_field` AS `table_name__bool_field` " .
                      "FROM `table_name`";

        return [
            [ // #0  no tokens, the simplest select
                [],
                $defaultSql
            ],
            [ // #1 simple where clause
                [
                    ["token", "where"],
                    ["reference", "field", "other_field", ""],
                    ["value", "operator", "="],
                    ["value", "string", "value"]
                ],
                $defaultSql . " WHERE `other_field` = :string_0"
            ],
            [ // #2 selecting aggregate functions, with expressions as arguments and multiple functions of the same name
                [
                    ["value", "function", "sum"],
                    ["token", "open"],
                    ["reference", "field", "field1", ""],
                    ["value", "operator", "+"],
                    ["reference", "field", "field2", ""],
                    ["token", "close"],
                    ["value", "function", "sum"],
                    ["token", "open"],
                    ["reference", "field", "field3", ""],
                    ["token", "close"]
                ],
                "SELECT SUM(`field1` + `field2`) AS `sum`, SUM(`field3`) AS `sum_1` FROM `table_name`"
            ],
            [ // #3 selecting aggregate functions with arbitrary nested closures and functions
                [
                    ["value", "function", "avg"],
                    ["token", "open"],
                    ["reference", "field", "field1", ""],
                    ["value", "operator", "+"],
                    ["token", "open"],
                    ["reference", "field", "field2", ""],
                    ["value", "operator", "-"],
                    ["value", "function", "unix_timestamp"],
                    ["token", "open"],
                    ["token", "close"],
                    ["token", "close"],
                    ["token", "close"]
                ],
                "SELECT AVG(`field1` + ( `field2` - UNIX_TIMESTAMP() )) AS `avg` FROM `table_name`"
            ],
            [ // #4 selecting aggregate functions with arbitrary nested closures and functions, where the closure is the first operand
                [
                    ["value", "function", "avg"],
                    ["token", "open"],
                    ["token", "open"],
                    ["reference", "field", "field2", ""],
                    ["value", "operator", "-"],
                    ["value", "function", "unix_timestamp"],
                    ["token", "open"],
                    ["token", "close"],
                    ["token", "close"],
                    ["value", "operator", "+"],
                    ["reference", "field", "field1", ""],
                    ["token", "close"]
                ],
                "SELECT AVG(( `field2` - UNIX_TIMESTAMP() ) + `field1`) AS `avg` FROM `table_name`"
            ],
            [ // #5 using functions with multiple arguments
                [
                    ["token", "where"],
                    ["reference", "field", "other_field", ""],
                    ["value", "operator", ">"],
                    ["value", "function", "ifnull"],
                    ["token", "open"],
                    ["reference", "field", "int_field", ""],
                    ["value", "int", 0],
                    ["token", "close"],
                ],
                $defaultSql . " WHERE `other_field` > IFNULL(`int_field`, :int_0)"
            ]
        ];
    }

    /**
     * @dataProvider complexTokenSequenceProvider
     *
     * @param array $tokens
     * @param array $metadataConfig
     * @param $expectedSql
     */
    public function testInterpretingMultipleIncludes(array $tokens, array $metadataConfig, $expectedSql)
    {
        $includes = $this->configureMetadataProvider($metadataConfig);

        $targetMetadata = array_shift($metadataConfig);

        $tokenSequencer = $this->createMockTokenSequencer($tokens, $targetMetadata["class"], $includes);

        $interpreter = new FindInterpreter();
        $interpreter->setEntityMetadataProvider($this->metadataProvider);

        /** @var TokenSequencerInterface $tokenSequencer */
        $this->assertEquals($expectedSql, $interpreter->interpretQuery($tokenSequencer));
    }

    public function complexTokenSequenceProvider()
    {
        return [
            [ // #0 single include
                [
                    ["token", "left"],
                    ["token", "join"],
                    ["reference", "collection", "two", ""],
                    ["token", "on"],
                    ["token", "open"],
                    ["reference", "field", "one.one_id", ""],
                    ["value", "operator", "="],
                    ["reference", "field", "two.one_id", ""],
                    ["token", "close"],
                    ["token", "where"],
                    ["reference", "field", "one.field1", ""],
                    ["value", "operator", "between"],
                    ["value", "int", 2],
                    ["token", "and"],
                    ["value", "int", 3]
                ],
                [
                    "one" => [
                        "class" => "OneModel",
                        "collection" => "one",
                        "fields" => ["field1", "field2"],
                        "pk" => "one_id",
                        "dontInclude" => true
                    ],
                    "two" => [
                        "class" => "TwoModel",
                        "collection" => "two",
                        "fields" => ["field3", "field4"]
                    ]
                ],
                "SELECT `one`.`field1` AS `one__field1`, `one`.`field2` AS `one__field2`, `two`.`field3` AS `two__field3`, `two`.`field4` AS `two__field4` " .
                "FROM `one` LEFT JOIN `two` ON ( `one`.`one_id` = `two`.`one_id` ) " .
                "WHERE `one`.`field1` BETWEEN :int_0 AND :int_1"
            ],
            [ // #1 two includes
                [
                    ["token", "left"],
                    ["token", "join"],
                    ["reference", "collection", "two", ""],
                    ["token", "on"],
                    ["token", "open"],
                    ["reference", "field", "one.one_id", ""],
                    ["value", "operator", "="],
                    ["reference", "field", "two.one_id", ""],
                    ["token", "close"],
                    ["token", "left"],
                    ["token", "join"],
                    ["reference", "collection", "three", ""],
                    ["token", "on"],
                    ["token", "open"],
                    ["reference", "field", "one.one_id", ""],
                    ["value", "operator", "="],
                    ["reference", "field", "three.one_id", ""],
                    ["token", "close"],
                    ["token", "where"],
                    ["reference", "field", "one.field1", ""],
                    ["value", "operator", "between"],
                    ["value", "int", 2],
                    ["token", "and"],
                    ["value", "int", 3]
                ],
                [
                    "one" => [
                        "class" => "OneModel",
                        "fields" => ["one_id", "field1", "field2"],
                        "pk" => "one_id",
                        "dontInclude" => true
                    ],
                    "two" => [
                        "class" => "TwoModel",
                        "fields" => ["id", "field3", "field4"]
                    ]
                    ,
                    "three" => [
                        "class" => "ThreeModel",
                        "fields" => ["id", "field5", "field6"]
                    ]
                ],
                "SELECT * FROM (" .
                    "SELECT " .
                        "`one`.`one_id` AS `one__one_id`, `one`.`field1` AS `one__field1`, `one`.`field2` AS `one__field2`, " .
                        "`two`.`id` AS `two__id`, `two`.`field3` AS `two__field3`, `two`.`field4` AS `two__field4`, " .
                        "`three`.`id` AS `three__id`, `three`.`field5` AS `three__field5`, `three`.`field6` AS `three__field6` " .
                    "FROM `one` " .
                        "LEFT JOIN `two` ON ( `one`.`one_id` = `two`.`one_id`) " .
                        "LEFT JOIN `three` ON (`three`.`id` IS NULL) " .
                    "WHERE `one`.`field1` BETWEEN :int_0 AND :int_1 " .
                    "UNION " .
                    "SELECT " .
                        "`one`.`one_id` AS `one__one_id`, `one`.`field1` AS `one__field1`, `one`.`field2` AS `one__field2`, " .
                        "`two`.`id` AS `two__id`, `two`.`field3` AS `two__field3`, `two`.`field4` AS `two__field4`, " .
                        "`three`.`id` AS `three__id`, `three`.`field5` AS `three__field5`, `three`.`field6` AS `three__field6` " .
                    "FROM `one` " .
                        "LEFT JOIN `two` ON (`two`.`id` IS NULL) " .
                        "LEFT JOIN `three` ON ( `one`.`one_id` = `three`.`one_id`) " .
                    "WHERE `one`.`field1` BETWEEN :int_0 AND :int_1" .
                ") s ORDER BY IFNULL(`one__one_id`, :largest_value), IFNULL(`two__id`, :largest_value), IFNULL(`three__id`, :largest_value)"
            ],
            [ // #2 two includes, including many to many join and target collection sort field
                [
                    ["token", "left"],
                    ["token", "join"],
                    ["reference", "collection", "two", ""],
                    ["token", "on"],
                    ["token", "open"],
                    ["reference", "field", "one.id", ""],
                    ["value", "operator", "="],
                    ["reference", "field", "two.one_id", ""],
                    ["token", "close"],
                    ["token", "left"],
                    ["token", "join"],
                    ["reference", "collection", "one_three", ""],
                    ["token", "on"],
                    ["token", "open"],
                    ["reference", "field", "one.id", ""],
                    ["value", "operator", "="],
                    ["reference", "field", "one_three.one_id", ""],
                    ["token", "close"],
                    ["token", "left"],
                    ["token", "join"],
                    ["reference", "collection", "three", ""],
                    ["token", "on"],
                    ["token", "open"],
                    ["reference", "field", "one_three.three_id", ""],
                    ["value", "operator", "="],
                    ["reference", "field", "three.id", ""],
                    ["token", "close"],
                    ["token", "sort"],
                    ["reference", "field", "one.field1", ""],
                    ["value", "sort-direction", "desc"]
                ],
                [
                    "one" => [
                        "class" => "OneModel",
                        "fields" => ["id", "field1", "field2"],
                        "dontInclude" => true
                    ],
                    "two" => [
                        "class" => "TwoModel",
                        "fields" => ["id", "field3", "field4"]
                    ]
                    ,
                    "three" => [
                        "class" => "ThreeModel",
                        "fields" => ["id", "field5", "field6"]
                    ]
                ],
                "SELECT * FROM (" .
                    "SELECT " .
                        "`one`.`id` AS `one__id`, `one`.`field1` AS `one__field1`, `one`.`field2` AS `one__field2`, " .
                        "`two`.`id` AS `two__id`, `two`.`field3` AS `two__field3`, `two`.`field4` AS `two__field4`, " .
                        "`three`.`id` AS `three__id`, `three`.`field5` AS `three__field5`, `three`.`field6` AS `three__field6` " .
                    "FROM `one` " .
                        "LEFT JOIN `two` ON ( `one`.`id` = `two`.`one_id`) " .
                        "LEFT JOIN `one_three` ON (`one_three`.`id` IS NULL) " .
                        "LEFT JOIN `three` ON (`three`.`id` IS NULL) " .
                    "UNION " .
                    "SELECT " .
                        "`one`.`id` AS `one__id`, `one`.`field1` AS `one__field1`, `one`.`field2` AS `one__field2`, " .
                        "`two`.`id` AS `two__id`, `two`.`field3` AS `two__field3`, `two`.`field4` AS `two__field4`, " .
                        "`three`.`id` AS `three__id`, `three`.`field5` AS `three__field5`, `three`.`field6` AS `three__field6` " .
                    "FROM `one` " .
                        "LEFT JOIN `two` ON (`two`.`id` IS NULL) " .
                        "LEFT JOIN `one_three` ON ( `one`.`id` = `one_three`.`one_id`) " .
                        "LEFT JOIN `three` ON ( `one_three`.`three_id` = `three`.`id`)" .
                ") s ORDER BY `one__field1` DESC, IFNULL(`two__id`, :largest_value), IFNULL(`three__id`, :largest_value)"
            ],
            [ // #3 four includes, including child relationships
                [
                    ["token", "left"],
                    ["token", "join"],
                    ["reference", "collection", "two", ""],
                    ["token", "on"],
                    ["token", "open"],
                    ["reference", "field", "one.id", ""],
                    ["value", "operator", "="],
                    ["reference", "field", "two.one_id", ""],
                    ["token", "close"],
                    ["token", "left"],
                    ["token", "join"],
                    ["reference", "collection", "three", ""],
                    ["token", "on"],
                    ["token", "open"],
                    ["reference", "field", "one.id", ""],
                    ["value", "operator", "="],
                    ["reference", "field", "three.one_id", ""],
                    ["token", "close"],
                    ["token", "left"],
                    ["token", "join"],
                    ["reference", "collection", "four", ""],
                    ["token", "on"],
                    ["token", "open"],
                    ["reference", "field", "three.id", ""],
                    ["value", "operator", "="],
                    ["reference", "field", "four.three_id", ""],
                    ["token", "close"],
                    ["token", "left"],
                    ["token", "join"],
                    ["reference", "collection", "five", ""],
                    ["token", "on"],
                    ["token", "open"],
                    ["reference", "field", "three.id", ""],
                    ["value", "operator", "="],
                    ["reference", "field", "five.three_id", ""],
                    ["token", "close"],
                ],
                [
                    "one" => [
                        "class" => "OneModel",
                        "fields" => ["id", "field1", "field2"],
                        "dontInclude" => true
                    ],
                    "two" => [
                        "class" => "TwoModel",
                        "fields" => ["id", "field3", "field4"]
                    ]
                    ,
                    "three" => [
                        "class" => "ThreeModel",
                        "fields" => ["id", "field5", "field6"]
                    ],
                    "four" => [
                        "class" => "FourModel",
                        "fields" => ["id", "field7"]
                    ]
                    ,
                    "five" => [
                        "class" => "FiveModel",
                        "fields" => ["id", "field8"]
                    ]
                ],
                "SELECT * FROM (" .
                    "SELECT " .
                        "`one`.`id` AS `one__id`, `one`.`field1` AS `one__field1`, `one`.`field2` AS `one__field2`, " .
                        "`two`.`id` AS `two__id`, `two`.`field3` AS `two__field3`, `two`.`field4` AS `two__field4`, " .
                        "`three`.`id` AS `three__id`, `three`.`field5` AS `three__field5`, `three`.`field6` AS `three__field6`, " .
                        "`four`.`id` AS `four__id`, `four`.`field7` AS `four__field7`, " .
                        "`five`.`id` AS `five__id`, `five`.`field8` AS `five__field8` " .
                    "FROM `one` " .
                        "LEFT JOIN `two` ON ( `one`.`id` = `two`.`one_id`) " .
                        "LEFT JOIN `three` ON (`three`.`id` IS NULL) " .
                        "LEFT JOIN `four` ON (`four`.`id` IS NULL) " .
                        "LEFT JOIN `five` ON (`five`.`id` IS NULL) " .
                    "UNION " .
                    "SELECT " .
                        "`one`.`id` AS `one__id`, `one`.`field1` AS `one__field1`, `one`.`field2` AS `one__field2`, " .
                        "`two`.`id` AS `two__id`, `two`.`field3` AS `two__field3`, `two`.`field4` AS `two__field4`, " .
                        "`three`.`id` AS `three__id`, `three`.`field5` AS `three__field5`, `three`.`field6` AS `three__field6`, " .
                        "`four`.`id` AS `four__id`, `four`.`field7` AS `four__field7`, " .
                        "`five`.`id` AS `five__id`, `five`.`field8` AS `five__field8` " .
                    "FROM `one` " .
                        "LEFT JOIN `two` ON (`two`.`id` IS NULL) " .
                        "LEFT JOIN `three` ON ( `one`.`id` = `three`.`one_id`) " .
                        "LEFT JOIN `four` ON ( `three`.`id` = `four`.`three_id`) " .
                        "LEFT JOIN `five` ON (`five`.`id` IS NULL) " .
                    "UNION " .
                    "SELECT " .
                        "`one`.`id` AS `one__id`, `one`.`field1` AS `one__field1`, `one`.`field2` AS `one__field2`, " .
                        "`two`.`id` AS `two__id`, `two`.`field3` AS `two__field3`, `two`.`field4` AS `two__field4`, " .
                        "`three`.`id` AS `three__id`, `three`.`field5` AS `three__field5`, `three`.`field6` AS `three__field6`, " .
                        "`four`.`id` AS `four__id`, `four`.`field7` AS `four__field7`, " .
                        "`five`.`id` AS `five__id`, `five`.`field8` AS `five__field8` " .
                    "FROM `one` " .
                        "LEFT JOIN `two` ON (`two`.`id` IS NULL) " .
                        "LEFT JOIN `three` ON ( `one`.`id` = `three`.`one_id`) " .
                        "LEFT JOIN `four` ON (`four`.`id` IS NULL) " .
                        "LEFT JOIN `five` ON ( `three`.`id` = `five`.`three_id`)" .
                ") s ORDER BY IFNULL(`one__id`, :largest_value), IFNULL(`two__id`, :largest_value), IFNULL(`three__id`, :largest_value), IFNULL(`four__id`, :largest_value), IFNULL(`five__id`, :largest_value)"
            ]
        ];
    }

}
 