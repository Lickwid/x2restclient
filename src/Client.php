<?php namespace Oca\X2RestClient;

use Exception;
use GuzzleHttp\Client as GuzzleClient;
use HTMLPurifier, HTMLPurifier_Config;

class Client
{
    private $guzzle;
    private $purify;

    /**
     * Client constructor.
     * @param $base_url
     * @param $apiUser
     * @param $apiKey
     * @param bool|true $purify
     */
    public function __construct($base_url, $apiUser, $apiKey, $purify = true)
    {
        $this->setPurify($purify);

        // just a guzzle config
        $config = array(
            'base_url' => $base_url,
            'defaults' => [
                'headers' => ['Content-Type' => 'application/json'],
                'auth' => [$apiUser, $apiKey],
            ],
        );
        $this->guzzle = new GuzzleClient($config);
    }

    /**
     * Create a contact. Return the contact with infomration on ignored fields, or return information on what information
     * is missing.
     *
     * @param $submittedFields
     * @param null $mapper
     * @param bool|true $verfityDropdowns
     * @param null $updateId
     * @return array
     * @throws Exception
     */
    public function createContact( $submittedFields, $mapper = null, $verfityDropdowns = true, $updateId = null){
        /**
         * @todo tracking key handling
         * @todo fingerprint handling
         */

        $fieldInfo = $this->verifyAttributes('Contacts', $submittedFields, $mapper, $verfityDropdowns);

        if($updateId){
            // update contact
            // set dupecheck to zero
            if(!isset($fieldInfo['verifiedFields']['dupeCheck']))
                $fieldInfo['verifiedFields']['dupeCheck'] = 0;

            //Set visibility
            if (empty ($fieldInfo['verifiedFields']['visibility'])) $fieldInfo['verifiedFields']['visibility'] = 1;

            // post it to x2engine
            $res = $this->guzzle->put( 'Contacts/' . $updateId . '.json' , ['body' => json_encode($fieldInfo['verifiedFields'])] );
        } else {
            // create contact

            //Set visibility
            if (empty ($fieldInfo['verifiedFields']['visibility'])) $fieldInfo['verifiedFields']['visibility'] = 1;

            // verify we have all our needed "required" fields... Not needed if updating (probably)
            if(isset($fieldInfo['missingRequired']) && !empty($fieldInfo['missingRequired'])){
                return array('missingRequired' => $fieldInfo['missingRequired']);
            }

            // post it to x2engine
            $res = $this->guzzle->post( 'Contacts', ['body' => json_encode($fieldInfo['verifiedFields'])] );
        }

        $contact = $res->json();

        if ( isset($contact['id']) ){
            return array('contact' => $contact, 'ignoredFields' => $fieldInfo['ignoredFields']);
        }

        throw new Exception("No contact ID returned.  Something must have gone wrong.");
    }

    /**
     * Update a Contact.
     *
     * @param $id
     * @param $submittedFields
     * @param null $mapper
     * @param bool|true $verifyDropdowns
     * @return array
     * @throws Exception
     */
    public function updateContact($id, $submittedFields, $mapper = null, $verifyDropdowns = true){
        return $this->createContact($submittedFields,$mapper,$verifyDropdowns,$id);
    }

    /**
     * Validate that the fields passed include all required fields. Retrieves required fields by the entity type.
     *
     * @param $entity
     * @param $fields
     * @return array|null
     */
    public function validateRequiredFields($entity, $fields){
        // verify we have all our needed "required" fields
        $requiredFields = $this->getRequiredFields($entity);
        $missingRequired = array();
        foreach($requiredFields as $fieldName => $field){
            if ( !isset($fields[$fieldName]) )
                $missingRequired[$fieldName] = "Missing needed required field: $fieldName.";
        }

        if(empty($missingRequired))
            return null;

        return $missingRequired;
    }

    /**
     * Get any actions available via the entity type.
     * @param $entity
     * @param $Id
     * @param bool|true $sortById
     * @return array
     */
    public function getEntityActions($entity, $Id, $sortById = true){
        $res = $this->guzzle->get("$entity/$Id/Actions");

        // return them with the action ID as key in the array
        if($sortById){
            $actions = array();
            foreach($res->json() as $action){
                $actions[$action['id']] = $action;
            }
            return $actions;
        }

        return $res->json();
    }

    /**
     * Create an action for an entity.
     *
     * @param $entity
     * @param $entityId
     * @param $description
     * @param string $type
     * @return mixed
     */
    public function createAction($entity, $entityId, $description, $type = 'note'){
        $actionData = array(
            'actionDescription' => $description,
            'associationId' => $entityId,
            'associationType' => $entity,
            'type' => $type,
            "visibility" => "1",
            "createDate" => time(),
        );

        $res = $this->guzzle->post( "$entity/$entityId/Actions", ['body' => json_encode($actionData)] );

        return $res->json();
    }

    /**
     * Get the tags for an entity (contact, etc) based on it's ID
     *
     * @param $entity
     * @param $Id
     * @return mixed|JSON
     */
    public function getEntityTags($entity, $Id){
        $res = $this->guzzle->get("$entity/$Id/tags");

        return $res->json();
    }

    /**
     * Create a tag for an entity, based on it's ID
     *
     * @param $entity
     * @param $Id
     * @param $tagList
     * @return mixed
     */
    public function createTags($entity, $Id, $tagList){
        $hashedTags = array();
        foreach ($tagList as $tag) {
            $hashedTags[] = '#'.ltrim(trim($tag), '#'); // Auto-prepend "#" if missing;
        }

        $res = $this->guzzle->post("$entity/$Id/tags", ['body' => json_encode($hashedTags)] );
        return $res->json();
    }

    /**
     * @param $entity
     * @param $entityId
     * @return mixed
     */
    public function getEntity($entity, $entityId){
        $res = $this->guzzle->get( "$entity/$entityId.json" );
        return $res->json();
    }

    /**
     * Checks the given fields against the given entity type's fields.  Returns fields that are verified and ignored.
     *  Also returns a list of the field names for the entity.
     *
     * You can provide a mapper as well if your field names aren't the same as the entities field names.
     *
     * @param $entity
     * @param $submittedFields
     * @param bool $verifyDropdowns
     * @return array
     */
    public function verifyAttributes($entity, $submittedFields, $mapper = null, $verifyDropdowns = true){
        // get fieldnames to verify data
        $fieldNames = $this->getFields($entity, $verifyDropdowns);
        $verifiedFields = array();
        $ignoredFields = array();

        foreach($submittedFields as $key => $value){
            if(!empty($mapper) && isset($mapper[$key])){
                // check if the mapping was correct.
                $this->verifyGivenField($entity, $verifiedFields, $ignoredFields, $mapper[$key], $value, $fieldNames, $verifyDropdowns);
            }else{
                // No match in mapper, or mapper not provided assume it's a Contact attribute
                $this->verifyGivenField($entity, $verifiedFields, $ignoredFields, $key, $value, $fieldNames, $verifyDropdowns);
            }
        }

        // visibility is a required field not often set... lets set it.
        if (empty ($verifiedFields['visibility']))
            $verifiedFields['visibility'] = 1;

        $missingRequired = $this->validateRequiredFields($entity,$verifiedFields);

        return array(
            'verifiedFields' => $verifiedFields,
            'ignoredFields' => $ignoredFields,
            'fieldNames' => $fieldNames,
            'missingRequired' => $missingRequired,
        );
    }

    /**
     * Verifies a field. Can check if the field is a correct dropdown value, and if it's a valid field name.
     *
     * @param $verifiedFields
     * @param $ignoredFields
     * @param $fieldName
     * @param $fieldValue
     * @param null $fieldNames
     * @param bool $verifyDropdowns, For this to work you must make sure $fieldNames includes dropdowns ( see getFields() )
     */
    public function verifyGivenField($entity, &$verifiedFields, &$ignoredFields, $fieldName, $fieldValue, $fieldNames = null, $verifyDropdowns = false){
        if(empty($fieldNames)){
            $fieldNames = $this->getFields($entity, $verifyDropdowns);
        }
        // check if the mapping was correct.
        if(isset($fieldNames[$fieldName])){
            // verify the dropdown
            if( $verifyDropdowns && $fieldNames[$fieldName]['type'] == 'dropdown' && !isset($fieldNames[$fieldName]['dropdownInfo']['options'][$fieldValue]) ){
                $ignoredFields[$fieldName] = 'Not a valid dropdown value.';
            } else {
                $fieldValue = $this->purify($fieldValue);
                $verifiedFields[$fieldName] =$fieldValue;
            }
        } else {
            $ignoredFields[$fieldName] = 'Not a valid fieldname.';
//            throw new Exception($fieldName . ' is an invalid fieldName.');
        }
    }

    /**
     * @param array $emails, simple array of emails
     * @param array $otherEmailFields, array of fields that may have email addresses in them
     * @return null
     * @throws Exception
     */
    function getEmailduplicates($emails, $emailFields = array('email')){
        $updateContact = null;
        $otherContacts = array();

        if(empty($emails)){
            return null;
        }

        $emailDuplicates = $this->getContactsByEmails($emails, false);
        if(empty($emailDuplicates)){
            return null;
        }

        // process email fields - Usually you set "email" first so it is checked first.
        //  - if multiples for that email field, use the highest ID for that field
        foreach ($emailFields as $fieldname){
            $other = null;

            if(isset($emailDuplicates[$fieldname])){
                $others = $emailDuplicates[$fieldname];

                if(empty($updateContact)){ // if this is NOT set by $primary... set it.
                    // get the highest ID (see max()) if there are multiple contacts from $fieldname
                    $updateContact = $emailDuplicates[$fieldname][ max(array_keys($emailDuplicates[$fieldname])) ];

                    unset($others[$updateContact['id']]);  // unset $updateContact as its no longer an "other"
                }
                if(!empty($others))
                    $otherContacts[$fieldname] = $others;
            }
        }

        return array('contactToUpdate' => $updateContact, 'otherContacts' => $otherContacts);
    }

    /**
     * Returns an array of contact's attributes given an email
     *
     * @param array $emails
     * @param bool $flatten, false if you want the returned array to include search field names as keys
     * @return array|null
     * @throws Exception
     */
    public function getContactsByEmails($emails, $flatten = true, $dedup = true, $visibility = 1){
        $emailFields = $this->getEmailFields('Contacts');
        if(is_array($emails)){
            $contacts = array();
            foreach ($emailFields as $field) {
                $contactList = $this->getEntityByField('Contacts', $emails, $field['fieldName'], $visibility);
                if($contactList){ // if null... do nothing
                    $contacts[$field['fieldName']] = $this->flattenEntityList(array($contactList)); // use this function to get the contact list with ID's for keys
                }
            }

            if($dedup){
                $dedup = array();
                foreach($contacts as $fname => $clists){
                    foreach($clists as $key => $contact){
                        if( isset($dedup[$contact['id']]) ){
                            unset($contacts[$fname][$key]);
                        }
                        $dedup[$contact['id']] = 1;
                    }
                }
            }

            // loop through all emails and email fields
            /*
             * below is NOT needed b/c submitting an array of emails acts like an "or"
            foreach($emails as $email){
                // lets try an "_or" search by all the different email fields
                foreach ($emailFields as $field) {
                    $check = $this->getContactsByEmail($email, $field);
                    if($check){
                        $contactslist[] = $check;
                    }
                }
            }
            */
            if($flatten){
                $contacts = $this->flattenEntityList($contacts);
            }
            return $contacts;
        } else {
            throw new Exception('$emails should be an array');
        }
    }

    /**
     * Finds a contact in X2 using their name.  Should be "FirstName LastName"
     * @param $names
     * @return mixed|null
     * @throws Exception
     */
    public function getContactsByName($names){
        if(is_array($names)){
            return $this->getEntityByField('Contacts', $names, 'name');
        } else {
            throw new Exception('$names should be an array');
        }
    }

    /**
     * Gets an entity (contact, etc) by user specified field type.
     *
     * @param string $entity string, the type of record you are retrieving
     * @param array|string $searchInfo, the values you are searching with
     * @param string $fieldName
     * @param int $visibility
     * @return mixed|null
     * @internal param array|string $email
     * @internal param string $field
     */
    public function getEntityByField($entity, $searchInfo, $fieldName = 'email', $visibility = 1){
        //remove all empty items
        $searchInfo = array_filter($searchInfo);

        // if empty, return null
        if(empty($searchInfo)){
            return null;
        }

        // limit to 500, probably never have 500 contacts when checking for duplicates... so if we receive 500, we know something is wrong
        $query = array('_limit' => 500, $fieldName => $searchInfo);

        // some entities don't support visibility... so allow it to not be set.
        if($this->notEmpty($visibility)){
            $query['visibility'] = $visibility;
        }
        $query = http_build_query($query);
        $res = $this->guzzle->get("$entity?$query");
        $contacts = $res->json();
        if (count($contacts) == 500 ) {
            return null; // something must have gone wrong.
        }
        return $contacts;
    }

    /**
     *
     * This is used if you make multiple queries to x2.  Each query can give you 1 or more entities.  This will flatten those list of queries.
     * See getContactsByEmails()
     *
     * @param $list, a list of lists.  something like [0=>[0=>[firstname, lastname,etc],1=>[firstname,lastname,etc]],1=>....]
     * @return array|null, returns a
     */
    public function flattenEntityList($list, $idkeys = true){
        if(!is_array($list)){
            return null;
        }

        $flat = array();
        foreach($list as $items) {
            foreach($items as $item){
                if($idkeys){
                    $flat[$item['id']] = $item;
                } else {
                    $flat[] = $item;
                }
            }
        }

        return $flat;
    }

    /**
     * @param string $entity, example: "Contacts"
     * @param array $list, an array of Contacts
     * @throws Exception
     */
    public function resetAllDupeCheck($entity, $list){
        if(!is_array($list))
            throw new Exception('$list should be an array');

        foreach($list as $item){
            if(!isset($item['id']))
                throw new Exception('$list items should contain an ID.');

            $this->resetDupeCheck($entity,$item['id']);
        }
    }

    /**
     * @param $entity
     * @param $id
     * @return mixed
     */
    public function resetDupeCheck($entity, $id){
        $config = array(
            'dupeCheck' => 0,
        );
        $res = $this->guzzle->put("$entity/$id.json", ['body' => json_encode($config)]);
        return $res->json();
    }

    /**
     * @param bool|true $byId
     * @return array
     */
    public function getAllDropdowns($byId = true){
        $res = $this->guzzle->get('dropdowns');

        // return them with the dropdown ID as key in the array
        if($byId){
            $dropdowns = array();
            foreach($res->json() as $dropdown){
                $dropdowns[$dropdown['id']] = $dropdown;
            }
            return $dropdowns;
        }

        return $res->json();
    }

    /**
     * @param $fieldId
     * @return mixed
     */
    public function getDropdown($fieldId){
        $res = $this->guzzle->get("dropdowns/$fieldId.json");
        return $res->json();
    }

    /**
     * @param $entity
     * @return array
     */
    public function getEmailFields($entity){
        $fields = $this->getFields($entity);
        $emailFields = array();
        foreach($fields as $field){
            // check by name name
            if (strpos($field['fieldName'],'email') !== false) {
                $emailFields[$field['fieldName']] = $field;
                continue;
            }

            // check by type
            if ( $field['type'] == 'email' ){
                $emailFields[$field['fieldName']] = $field;
                continue;
            }
        }

        return $emailFields;
    }

    /**
     * @param $entity
     * @return array
     */
    public function getRequiredFields($entity){
        $fields = $this->getFields($entity);
        $emailFields = array();
        foreach($fields as $field){
            // check by name name
            if ($field['required']) {
                $emailFields[$field['fieldName']] = $field;
            }
        }

        return $emailFields;
    }

    /**
     * @param $entity, type of entity (Contacts, Accounts, etc...)
     * @param $name, name of the field
     * @param string $nameType, could also be attributeLabel
     * @return null
     */
    public function getFieldByName($entity, $name, $nameType = 'fieldName'){
        $fields = $this->getFields($entity);
        foreach($fields as $field){
            if($field[$nameType] == $name){
                return $field;
            }
        }
        return null;
    }

    /**
     * @param $entity, entity type.
     * @param bool $withDropdownOptions, this will include the dropdown info per dropdown field.
     * @return array
     */
    public function getFields($entity, $withDropdownOptions = false){
        $res = $this->guzzle->get("$entity/fields");

        $data = array();
        if($withDropdownOptions){
            $dropdowns = $this->getAllDropdowns();
        }

        foreach ($res->json() as $field) {
            $data[$field['fieldName']] = $field;
            if($withDropdownOptions && $field['type'] == 'dropdown' && isset($dropdowns[$field['linkType']])){
                $data[$field['fieldName']]['dropdownInfo'] = $dropdowns[$field['linkType']];
            }
        }
        return $data;
    }

    /**
     * Should the class always purify attributes before sending to x2engine?
     *
     * @param bool $value
     */
    public function setPurify($value){
        $this->purify = $value;
    }

    /**
     * return the value for whether or not attributes should be purified before sending to X2
     *
     * @return mixed
     */
    public function getPurify(){
        return $this->purify;
    }

    /**
     * The config is from x2engines getPurifier();
     * The code is fromt the second comment here: https://laracasts.com/discuss/channels/tips/htmlpurifier-in-laravel-5
     * Uses https://github.com/ezyang/htmlpurifier
     *
     * @param $string
     * @return string
     */
    public function purify($string){
        // @todo purify arrays
        if ($this->getPurify() && is_string($string)){
            $config = HTMLPurifier_Config::createDefault();
            $config->loadArray([
                'HTML.ForbiddenElements' => array(
                    'script', // Obvious reasons (XSS)
                    'form', // Hidden CSRF attempts?
                    'style', // CSS injection tomfoolery
                    'iframe', // Arbitrary location
                    'frame', // Same reason as iframe
                    'link', // Request to arbitrary location w/o user knowledge
                    'video', // No,
                    'audio', // No,
                    'object', // Definitely no.
                ),
                'HTML.ForbiddenAttributes' => array(
                    // Spoofing/mocking internal form elements:
                    '*@id',
                    '*@class',
                    '*@name',
                    // The event attributes should be removed automatically by HTMLPurifier by default
                ),
            ]);
            $purifier = new HTMLPurifier($config);
            return $purifier->purify($string);
        }

        return $string;
    }

    /**
     * Helper function from http://stackoverflow.com/a/733175
     *
     * @param $var
     * @return bool
     */
    public function notEmpty($var) {
        return ($var==="0"||$var);
    }
}
