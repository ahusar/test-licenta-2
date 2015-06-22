<?php

namespace Pitech\MigrationBundle\Adapter;

use Pitech\MainBundle\Entity\UserDailyClocking as NewUserDailyClocking;
use Pitech\MigrationBundle\Entity\PontaResource;
use Pitech\MigrationBundle\Helper\StringInterpreter;

class UserDailyClockingAdapter extends Adapter implements AdapterInterface
{
    /**
     * @param PontajResource $object
     *
     * @return NewUserDailyClocking
     */
    public function transform($object)
    {
        $user = $this->getNewUserFromPontaj($object);
        $pontaj = $object->getPontajResourcePontaj();
		        $days = $this->getDayAndHoursArray(
            $object->getPontajResourceDetails()
        );
        $pontajDay = $pontaj->getPontajDate();
        if ($user) {
            foreach ($days as $day => $value) {
                $newUserDailyClocking = $this
                    ->create($object, 'PitechMainBundle:UserDailyClocking');
                $newUserDailyClocking->setUser($user);
                $newUserDailyClocking
                    ->setDate($this->getDateOfClocking(clone $pontajDay, $day));
                $newUserDailyClocking
                    ->setValidatedAt($this->getValidationDate($pontaj));
                $this->em->persist($newUserDailyClocking);
            }
        }

        return true;
    }

    /**
     * Computes the validation date for daily clocking as the
     * next month, only if PontajResource object was validated
     * by the administrator
     *
     * @param PontajResource $pontaj
     *
     * @return \DateTime
     */
    public function getValidationDate($pontaj)
    {
        $pontajDate = clone $pontaj->getPontajDate();
        if ($pontaj->getPontajAdminValid()) {
            return $pontaj->getPontajLastActionDateTime() ?
                $pontaj->getPontajLastActionDateTime() :
                $pontajDate->modify($pontajDate->format('M') + 1 . ' month');
        }

        return null;
    }

    /**
     * Creates DateTime object from pontajResource date and
     * the day of daily clocking
     *
     * @param \DateTime $potanjDate - date of monthly clocking
     * @param int $dayOfClocking - the day for daily clocking
     *
     * @return \DateTime
     */
    public function getDateOfClocking($pontajDate, $dayOfClocking)
    {
        return $pontajDate
            ->setDate(
                $pontajDate->format('Y'),
                $pontajDate->format('m'),
                $dayOfClocking
            );
    }

    /**
     * Returns new user object from Pontaj
     *
     * @param PontajResource $object
     *
     * @return User
     */
    public function getNewUserFromPontaj($object)
    {
        return $this
            ->getInstance(
                'users',
                $object->getPontajResourceResource()->getNewId()
            );
    }

    /**
     * Get the hoursWorked element from serialized string
     *
     * @param String $string
     *
     * @return Array
     */
    public function getDayAndHoursArray($string)
    {
        $content = unserialize($string);
        
        return isset($content['hoursWorked']) ?
            $content['hoursWorked'] :
            array();
    }
}
