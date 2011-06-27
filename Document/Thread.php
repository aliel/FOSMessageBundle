<?php

namespace Ornicar\MessageBundle\Document;

use Ornicar\MessageBundle\Model\Thread as AbstractThread;
use Ornicar\MessageBundle\Model\MessageInterface;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use Ornicar\MessageBundle\Model\ParticipantInterface;

abstract class Thread extends AbstractThread
{
    /**
     * Messages contained in this thread
     *
     * @var Collection of MessageInterface
     */
    protected $messages;

    /**
     * Users participating in this conversation
     *
     * @var Collection of ParticipantInterface
     */
    protected $participants;

    /**
     * Tells, for each participant, if the message is deleted
     *
     * @var array of boolean indexed by user id
     */
    protected $isDeletedByParticipant = array();

    /**
     * Date the last messages were created at.
     * To each user id is associated the date
     * of the last message he did not write.
     *
     * This allows fast sorting of threads in inbox
     *
     * @var array of int timestamps indexed by user id
     */
    protected $datesOfLastMessageWrittenByOtherParticipant = array();

    /**
     * Date the last messages were created at.
     * To each user id is associated the date
     * of the last message he wrote.
     *
     * This allows fast sorting of threads in sentbox
     *
     * @var array of int timestamps indexed by user id
     */
    protected $datesOfLastMessageWrittenByParticipant = array();

    /**
     * All text contained in the thread messages
     * Used for the full text search
     *
     * @var string
     */
    protected $keywords = '';

    /**
     * Initializes the collections
     */
    public function __construct()
    {
        $this->messages = new ArrayCollection();
        $this->participants = new ArrayCollection();
    }

    /**
     * Gets the messages contained in the thread
     *
     * @return array of MessageInterface
     */
    public function getMessages()
    {
        return $this->messages->toArray();
    }

    /**
     * Adds a new message to the thread
     *
     * @param MessageInterface $message
     */
    public function addMessage(MessageInterface $message)
    {
        $this->messages->add($message);
        $this->denormalize();
    }

    /**
     * Gets the users participating in this conversation
     *
     * @return array of ParticipantInterface
     */
    public function getParticipants()
    {
        return $this->participants->toArray();
    }

    /**
     * Adds a participant to the thread
     * If it already exists, nothing is done.
     *
     * @param ParticipantInterface $participant
     * @return null
     */
    public function addParticipant(ParticipantInterface $participant)
    {
        if (!$this->isParticipant($participant)) {
            $this->participants->add($participant);
        }
    }

    /**
     * Tells if the user participates to the conversation
     *
     * @param ParticipantInterface $user
     * @return boolean
     */
    public function isParticipant(ParticipantInterface $user)
    {
        return $this->participants->contains($user);
    }

    /**
     * Tells if this thread is deleted by this participant
     *
     * @return bool
     */
    public function isDeletedByParticipant(ParticipantInterface $participant)
    {
        return $this->isDeletedByParticipant[$participant->getId()];
    }

    /**
     * Sets whether or not this participant has deleted this thread
     *
     * @param ParticipantInterface $participant
     * @param boolean $isDeleted
     */
    public function setIsDeletedByParticipant(ParticipantInterface $participant, $isDeleted)
    {
        $this->isDeletedByParticipant[$participant->getId()] = (boolean) $isDeleted;
    }

    /**
     * DENORMALIZATION
     *
     * All following methods are relative to denormalization
     */

    /**
     * Performs denormalization tricks
     */
    protected function denormalize()
    {
        $this->doParticipants();
        $this->doKeywords();
        $this->doEnsureMessagesIsRead();
        $this->doDatesOfLastMessageWrittenByParticipant();
        $this->doDatesOfLastMessageWrittenByOtherParticipant();
        $this->doEnsureIsDeletedByParticipant();
    }

    /**
     * Ensures that the thread participants are up to date
     */
    protected function doParticipants()
    {
        foreach ($this->getMessages() as $message) {
            $this->addParticipant($message->getSender());
        }
    }

    /**
     * Adds all messages contents to the keywords property
     */
    protected function doKeywords()
    {
        $keywords = $this->getSubject();

        foreach ($this->getMessages() as $message) {
            $keywords .= ' '.$message->getBody();
        }

        // we only need each word once
        $this->keywords = implode(' ', array_unique(str_word_count(strtolower($keywords), 1)));
    }

    /**
     * Ensures that every message has a isRead flag for each participant
     */
    protected function doEnsureMessagesIsRead()
    {
        foreach ($this->getMessages() as $message) {
            $message->setIsReadByParticipant($message->getSender(), true);
            $message->ensureIsReadByParticipant($this->getParticipants());
        }
    }

    /**
     * Update the dates of last message written by participant
     */
    protected function doDatesOfLastMessageWrittenByParticipant()
    {
        $this->datesOfLastMessageWrittenByParticipant = $this->greaterMessageTimestampForCondition(
            $this->datesOfLastMessageWrittenByParticipant,
            function($participantId, $senderId) { return $participantId === $senderId; }
        );
    }

    /**
     * Update the dates of last message written by other participants
     */
    protected function doDatesOfLastMessageWrittenByOtherParticipant()
    {
        $this->datesOfLastMessageWrittenByOtherParticipant = $this->greaterMessageTimestampForCondition(
            $this->datesOfLastMessageWrittenByOtherParticipant,
            function($participantId, $senderId) { return $participantId !== $senderId; }
        );
    }

    /**
     * Gets dates of last message for each participant, depending on the condition
     *
     * @param array $dates
     * @param \Closure $condition
     * @return array
     */
    protected function greaterMessageTimestampForCondition(array $dates, \Closure $condition)
    {
        foreach ($this->getParticipants() as $participant) {
            $participantId = $participant->getId();
            $date = isset($dates[$participantId]) ? $dates[$participantId] : 0;
            foreach ($this->getMessages() as $message) {
                if ($condition($participantId, $message->getSender()->getId())) {
                    $date = max($date, $message->getTimestamp());
                }
            }
            $dates[$participantId] = $date;
        }

        return $dates;
    }

    /**
     * Ensures that each participant has an isDeleted flag
     */
    protected function doEnsureIsDeletedByParticipant()
    {
        foreach ($this->getParticipants() as $participant) {
            if (!isset($this->isDeletedByParticipant[$participant->getId()])) {
                $this->isDeletedByParticipant[$participant->getId()] = false;
            }
        }
    }
}
