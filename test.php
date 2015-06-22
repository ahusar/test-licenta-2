<?php

namespace Pitech\MigrationBundle\Helper;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

class QueryHelper
{
    /**
     * @var EntityManagerInterface
     */
    protected $em;

    /**
     * @var EntityManagerInterface
     */
    protected $oldEm;

    /**
     * @param   EntityManagerInterface  $em
     * @param   EntityManagerInterface  $oldEm
     */
    public function __construct(EntityManagerInterface $em, EntityManagerInterface $oldEm)
    {
        $this->em = $em;
        $this->oldEm = $oldEm;
    }

    /**
     * Create a Query to get Objects by class $class, for action $action,
     * limit to $maxResults, set first result to $firstResult, select depending
     * $select, and add conditions $conditions.
     *
     * @param   string  $class
     * @param   string  $action
     * @param   int     $maxResults
     * @param   string  $select
     * @param   array   $conditions
     *
     * @return  Doctrine\ORM\Query
     */
    public function getQuery($class, $action = 'create', $maxResults = null, $select = null, $conditions = array())
    {
        $qb = $this->createQueryBuilder($class);
        $this->addSelect($qb, $class, $select);

        if (!empty($maxResults)) {
            $qb->setMaxResults($maxResults);
        }

        foreach ($conditions as $property => $value) {
            $qb->andWhere('o.' . $property . '= ' . $value);
        }

        $this->addCondition($qb, $action, $class);

        return $qb->getQuery();
    }

    /**
     * Get query builder for class, also adds joins with relevant associations defined in
     * getAssociation function for class $class
     *
     * @param   string  $class
     *
     * @return Doctrine\ORM\QueryBuilder
     */
    public function createQueryBuilder($class)
    {
        $qb = $this->oldEm->getRepository($class)->createQueryBuilder('o');
        $associations = $this->getAssociations($class);

        foreach ($associations as $alias => $associatedClass) {
            $association = $this->oldEm->getClassMetaData($class)->getAssociationsByTargetClass($associatedClass);
            if (count($association)) {
                $fieldName = reset($association)['fieldName'];
                switch ($fieldName) {
                    case 'pontajResourcePontaj':
                        $qb->innerJoin(
                            sprintf('o.%s', $association['fieldName']),
                            $alias,
                            'WITH',
                            $qb->expr()->isNull(sprintf('%s.newId', $alias))
                        );
                        break;
                    default:
                        $qb->innerJoin(
                            sprintf('o.%s', $association['fieldName']),
                            $alias,
                            'WITH',
                            $qb->expr()->isNotNull(sprintf('%s.newId', $alias))
                        );
                        break;
                }
            }
        }

        return $qb;
    }

    /**
     *  Add select condition.
     *
     *  Example: select only count(ids), or also select from join.
     *
     * @param   QueryBuilder    $qb
     * @param   string          $class
     * @param   string          $select
     *
     * @return  QueryBuilder
     */
    public function addSelect(QueryBuilder $qb, $class, $select = null)
    {
        switch ($select) {
            case 'count':
                $id = $this->oldEm->getClassMetaData($class)->getIdentifier()[0];
                $qb->select('count(o.' . $id . ')');
                break;
            case (preg_match('/.*?Entity.*?/', $select) == 1):
                $this->getAssociationSelect($qb, $class, $select);
                break;
            default:
                $qb->select($select);
                break;
        }

        return $qb;
    }

    /**
     *  Get select fields for assoctiation select
     *
     * @param   QueryBuilder    $qb
     * @param   string          $class
     * @param   string          $select
     *
     * @return  QueryBuilder
     */
    public function getAssociationSelect(QueryBuilder $qb, $class, $select)
    {
        $associtations = $this->getAssociations($class);
        if (in_array($select, $associtations)) {
            $alias = array_keys($associtations, $select);
            $id = $this->oldEm->getClassMetaData($select)->getIdentifier()[0];
            $qb->select(array_shift($alias) . '.' . $id . ' as groupId')->distinct();
        }

        return $qb;
    }

    /**
     * Add condition on query depending on class and action
     *
     * @param   QueryBuilder    $qb
     * @param   string          $action
     * @param   string          $class
     *
     * @return  QueryBuilder
     */
    public function addCondition(QueryBuilder $qb, $action, $class)
    {
        switch ($action) {
            case 'update':
                $condition = $this->getUpdateCondition($qb, $class);
                break;
            default:
                $condition = $qb->expr()->isNull('o.newId');
                break;
        }

        return $qb->andWhere($condition);
    }

    /**
     *  Add update contidion to QueryBuilder $qb
     *
     * @param   QueryBuilder    $qb
     * @param   string          $class
     *
     * @return  QueryBuilder
     */
    private function getUpdateCondition(QueryBuilder $qb, $class)
    {
        switch ($class) {
            case 'PitechMigrationBundle:TaskLog':
                $condition = $this->getTaskLogCondition($qb);
                break;
            case 'PitechMigrationBundle:Pontaj':
                $condition = $this->getClockingCondition($qb);
                break;
            default:
                $condition = $qb->expr()->isNotNull('o.newId');
                break;
        }
        return $condition;
    }

    /**
     *  Special condition for Tasklogs
     *
     * @param   QueryBuilder    $qb
     *
     * @return  QueryBuilder
     */
    private function getTaskLogCondition(QueryBuilder $qb)
    {
        $notValidatedTaskLogs = $this->em->getRepository('PitechMainBundle:TaskLog')->getTaskLogsIds(false);

        return empty($notValidatedTaskLogs) ?
            $qb->expr()->isNull('o.taskLogId') :
            $qb->expr()->in('o.newId', $notValidatedTaskLogs);
    }

    /**
     *  Special condition for Clocking
     *
     * @param   QueryBuilder    $qb
     *
     * @return  QueryBuilder
     */
    private function getClockingCondition(QueryBuilder $qb)
    {
        $qb->andWhere('o.pontajAdminValid = :valid')
            ->setParameter('valid', 1);

        $lastValidDate = $this->em->getRepository('PitechMainBundle:Clocking')->getLatestValidationDate();

        return empty($lastValidDate) ?
            $qb->expr()->isNull('o.pontajId') :
            $qb->expr()->gte('o.pontajDate', $lastValidDate->format('Y-m-d'));
    }

    /**
     *  Returns array with association alias and class, for class $class
     *
     * @param   string  $class
     *
     * @return  array
     */
    public function getAssociations($class)
    {
        $associations = array();
        switch ($class) {
            case 'PitechMigrationBundle:Holiday':
            case 'PitechMigrationBundle:UserProfile':
            case 'PitechMigrationBundle:UserUnit':
            case 'PitechMigrationBundle:Contact':
                $associations = array('u' => 'Pitech\MigrationBundle\Entity\User');
                break;
            case 'PitechMigrationBundle:PontajResource':
                $associations = array(
                    't' => 'Pitech\MigrationBundle\Entity\Pontaj'
                );
                break;
            case 'PitechMigrationBundle:Project':
                $associations = array(
                    'u' => 'Pitech\MigrationBundle\Entity\User',
                    'c' => 'Pitech\MigrationBundle\Entity\Company'
                );
                break;
            case 'PitechMigrationBundle:TaskLog':
                $associations = array(
                    'u' => 'Pitech\MigrationBundle\Entity\User',
                    't' => 'Pitech\MigrationBundle\Entity\Task'
                );
                break;
            case 'Pitech\MigrationBundle\Entity\Task':
                $associations = array('p' => 'Pitech\MigrationBundle\Entity\Project');
        }
        return $associations;
    }
}
