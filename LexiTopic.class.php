<?php

class LexiTopic extends WireData {

    protected $id;
    protected $identifier;
    protected $title;
    protected $description;
    protected $translations = array();

    /**
     * Regular expression for allowed characters in identifiers and keys.
     */
    const REGEX_VALID_KEYS_IDENTIFIER = '/^[a-zA-Z0-9\-_]+$/';

    /**
     * Identifier of default topic, can't be changed by user
     */
    const DEFAULT_TOPIC_IDENTIFIER = 'default';


    public function __construct($id=0) {
        if ($id) $this->load(array('id' => $id));
        $this->setTrackChanges(true);
    }


    /** PUBLIC METHODS */


    /**
     * Get instance by ID
     *
     * @param $id ID of the topic
     * @return LexiTopic
     */
    public static function getTopicById($id) {
        $instance = new self($id);
        return $instance;
    }

    /**
     * Get instance by identifier
     *
     * @param string $identifier Identifier of topic
     * @return LexiTopic
     */
    public static function getTopicByIdentifier($identifier) {
        $instance = new self();
        $instance->load(array('identifier' => $identifier));
        return $instance;
    }

    /**
     * Check if a translation key or topic identifier is valid. Spaces and dots are not allowed.
     *
     * @param $str Key or topic
     * @return bool
     */
    public static function isValidKeyOrTopicIdentifier($str) {
        return preg_match(self::REGEX_VALID_KEYS_IDENTIFIER, $str);
    }

    /**
     * Get a translation from this topic
     *
     * @param string $key
     * @param int $langId
     * @throws WireException
     * @return string
     */
    public function getTranslation($key, $langId) {
        if (!$this->languages->get((int) $langId)->id) throw new WireException("Language passed to LexiTopic::get() not found");
        if (!isset($this->translations[$langId])) $this->loadTranslations($langId);
        return (isset($this->translations[$langId][$key])) ? $this->translations[$langId][$key] : '';
    }

    /**
     * Insert or update a translation into this topic
     *
     * @param string $key
     * @param string $value
     * @param int $langId
     * @throws WireException
     * @return void
     */
    public function setTranslation($key, $value, $langId) {
        // Check if both, language and key are valid
        if (!$this->languages->get((int) $langId)->id) throw new WireException("Language passed to LexiTopic::set() not found");
        if (!self::isValidKeyOrTopicIdentifier($key)) throw new WireException("Key {$key} is not valid");

        // Everything seems fine. Set the new value in the provided language
        // If it is a new entry, also create the keys for other languages with empty strings
        // because we want to have all keys present for all languages always
        if (!isset($this->translations[$langId])) $this->loadTranslations($langId);
        $isNew = !isset($this->translations[$langId][$key]);
        $this->translations[$langId][$key] = $value;
        $this->trackChange($langId);
        if ($isNew) {
            foreach ($this->languages as $lang) {
                if ($lang->id == $langId) continue;
                if (!isset($this->translations[$lang->id][$key])) { // Just to be sure not to overwrite a value...
                    $this->translations[$lang->id][$key] = '';
                    $this->trackChange($lang->id);
                }
            }
        }
    }

    /**
     * Save changed translations to database. Also create or update topic, if necessary.
     */
    public function save() {
        if (!$this->id) {
            $this->create();
        } else if ($this->changedMembers()) {
            $sql = 'UPDATE ' . Lexi::DB_NAME_TOPICS . ' SET title = :title, description = :description,
                    identifier = :identifier WHERE id = :id';
            $sth = $this->database->prepare($sql);
            $params = array(
                'title' => $this->title,
                'identifier' => $this->identifier,
                'description' => $this->description,
                'id' => $this->id,
            );
            $sth->execute($params);
            $this->untrackChange('title');
            $this->untrackChange('description');
            $this->untrackChange('identifier');
        }
        // Save changed translations
        foreach ($this->getChanges() as $langId) {
            $this->saveTranslations($langId);
        }
        $this->resetTrackChanges();
    }

    /**
     * Delete topic and its translations
     */
    public function delete() {
        if ($this->identifier == self::DEFAULT_TOPIC_IDENTIFIER)
            throw new WireException("Can't delete default topic");
        $sth = $this->database->prepare('DELETE FROM ' . Lexi::DB_NAME_TRANSLATIONS . ' WHERE topic_id = :id');
        $sth->execute(array('id' => $this->id));
        $sth = $this->database->prepare('DELETE FROM ' . Lexi::DB_NAME_TOPICS . ' WHERE id = :id');
        $sth->execute(array('id' => $this->id));
        $this->id = 0;
        $this->title = '';
        $this->description = '';
        $this->identifier = '';
    }

    /**
     * Delete a key for all languages
     *
     * @param $key
     */
    public function unsetKey($key) {
        foreach ($this->languages as $lang) {
            unset($this->translations[$lang->id][$key]);
        }
    }

    public function set($key, $value) {
        switch ($key) {
            case 'identifier':
                $this->setIdentifier($value);
                break;
            case 'title':
                $this->setTitle($value);
                break;
            case 'description':
                $this->setDescription($value);
                break;
            default:
                parent::set($key, $value);
        }
        return $this;
    }

    public function get($key) {
        $return = null;
        switch ($key) {
            case 'identifier':
                $return = $this->getIdentifier();
                break;
            case 'title':
                $return = $this->getTitle();
                break;
            case 'description':
                $return = $this->getDescription();
                break;
            default:
                $return = parent::get($key);
        }
        return $return;
    }

    /** GETTERS & SETTERS */


    public function setTitle($title) {
        $this->title = $title;
        $this->trackChange('title');
    }

    public function getTitle() {
        return $this->title;
    }

    public function setIdentifier($identifier) {
        if (!self::isValidKeyOrTopicIdentifier($identifier))
            throw new WireException("Identifier '{$identifier}' is not valid");
        if ($this->identifier == self::DEFAULT_TOPIC_IDENTIFIER) throw new WireException("Can't change default identifier");
        $this->identifier = $identifier;
        $this->trackChange('identifier');
    }

    public function getIdentifier() {
        return $this->identifier;
    }

    public function setDescription($description) {
        $this->description = $description;
        $this->trackChange('description');
    }

    public function getDescription() {
        return $this->description;
    }

    public function getId() {
        return $this->id;
    }


    /** PROTECTED METHODS */


    protected function changedMembers() {
        return ($this->changed('title') || $this->changed('description') || $this->changed('identifier'));
    }

    /**
     * Load topic either by ID or identifier. Translations ar loaded separately per language
     *
     * @param array $params
     * @throws WireException
     */
    protected function load(array $params) {
        $key = array_keys($params)[0];
        $sql = "SELECT * FROM " . Lexi::DB_NAME_TOPICS . " WHERE {$key} = :{$key}";
        $sth = $this->database->prepare($sql);
        $sth->execute(array($key => $params[$key]));
        if ($result = $sth->fetch()) {
            $this->id = (int) $result['id'];
            $this->title = $result['title'];
            $this->description = $result['description'];
            $this->identifier = $result['identifier'];
        } else {
            throw new WireException("Topic with {$key} '{$params[$key]}' does not exist");
        }
    }

    /**
     * Create a new Topic in Database
     */
    protected function create() {
        if (!$this->identifier) throw new WireException('You must set a topic identifier before calling LexiTopic::save()');
        $sql = "INSERT INTO " . Lexi::DB_NAME_TOPICS . " (`title`, `identifier`, `description`) VALUES (:title, :identifier, :description)";
        $sth = $this->database->prepare($sql);
        $sth->execute(array('title' => $this->title, 'identifier' => $this->identifier, 'description' => $this->description));
        $this->id = $this->database->lastInsertId();
        $sql = 'INSERT INTO ' . Lexi::DB_NAME_TRANSLATIONS . '(topic_id, lang_id) VALUES (:topic_id, :lang_id)';
        $sth = $this->database->prepare($sql);
        foreach ($this->languages as $lang) {
            $sth->execute(array('topic_id' => $this->id, 'lang_id' => $lang->id));
        }
    }

    /**
     * Load translations of a language from Database
     *
     * @param int $langId ID of language
     */
    protected function loadTranslations($langId) {
        if (!$this->id) {
            $this->translations[$langId] = array();
            return;
        }
        $sql = 'SELECT * FROM ' . Lexi::DB_NAME_TRANSLATIONS . ' WHERE topic_id = :id AND lang_id = :lang_id';
        $sth = $this->database->prepare($sql);
        $sth->execute(array('id' => $this->id, 'lang_id' => $langId));
        if ($result = $sth->fetch()) {
            $this->translations[$langId] = ($result['translations']) ? json_decode($result['translations'], true) : array();
        }
    }

    /**
     * Save translations of a language to Database
     *
     * @param int $langId ID of language
     */
    protected function saveTranslations($langId) {
        $sql = 'UPDATE ' . Lexi::DB_NAME_TRANSLATIONS . ' SET translations = :translations
                WHERE topic_id = :id AND lang_id = :lang_id';
        $translations = $this->translations[$langId];
        ksort($translations);
        $params = array(
            'translations' => json_encode($translations),
            'id' => $this->id,
            'lang_id' => $langId,
        );
        $sth = $this->database->prepare($sql);
        $sth->execute($params);
    }
} 