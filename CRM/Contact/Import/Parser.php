<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */
abstract class CRM_Contact_Import_Parser extends CRM_Import_Parser {

  protected $_tableName;

  /**
   * Total number of lines in file
   *
   * @var int
   */
  protected $_rowCount;

  /**
   * Running total number of un-matched Contacts.
   *
   * @var int
   */
  protected $_unMatchCount;

  /**
   * Array of unmatched lines.
   *
   * @var array
   */
  protected $_unMatch;

  /**
   * Total number of contacts with unparsed addresses
   * @var int
   */
  protected $_unparsedAddressCount;

  /**
   * Filename of mismatch data
   *
   * @var string
   */
  protected $_misMatchFilemName;

  protected $_primaryKeyName;
  protected $_statusFieldName;

  protected $fieldMetadata = [];
  /**
   * On duplicate
   *
   * @var int
   */
  public $_onDuplicate;

  /**
   * Dedupe rule group id to use if set
   *
   * @var int
   */
  public $_dedupeRuleGroupID = NULL;

  /**
   * Run import.
   *
   * @param string $tableName
   * @param array $mapper
   * @param int $mode
   * @param int $contactType
   * @param string $primaryKeyName
   * @param string $statusFieldName
   * @param int $onDuplicate
   * @param int $statusID
   * @param int $totalRowCount
   * @param bool $doGeocodeAddress
   * @param int $timeout
   * @param string $contactSubType
   * @param int $dedupeRuleGroupID
   *
   * @return mixed
   */
  public function run(
    $tableName,
    $mapper = [],
    $mode = self::MODE_PREVIEW,
    $contactType = self::CONTACT_INDIVIDUAL,
    $primaryKeyName = '_id',
    $statusFieldName = '_status',
    $onDuplicate = self::DUPLICATE_SKIP,
    $statusID = NULL,
    $totalRowCount = NULL,
    $doGeocodeAddress = FALSE,
    $timeout = CRM_Contact_Import_Parser::DEFAULT_TIMEOUT,
    $contactSubType = NULL,
    $dedupeRuleGroupID = NULL
  ) {

    // TODO: Make the timeout actually work
    $this->_onDuplicate = $onDuplicate;
    $this->_dedupeRuleGroupID = $dedupeRuleGroupID;

    switch ($contactType) {
      case CRM_Import_Parser::CONTACT_INDIVIDUAL:
        $this->_contactType = 'Individual';
        break;

      case CRM_Import_Parser::CONTACT_HOUSEHOLD:
        $this->_contactType = 'Household';
        break;

      case CRM_Import_Parser::CONTACT_ORGANIZATION:
        $this->_contactType = 'Organization';
    }

    $this->_contactSubType = $contactSubType;

    $this->init();

    $this->_rowCount = $this->_warningCount = 0;
    $this->_invalidRowCount = $this->_validCount = 0;
    $this->_totalCount = $this->_conflictCount = 0;

    $this->_errors = [];
    $this->_warnings = [];
    $this->_conflicts = [];
    $this->_unparsedAddresses = [];

    $this->_tableName = $tableName;
    $this->_primaryKeyName = $primaryKeyName;
    $this->_statusFieldName = $statusFieldName;

    if ($mode == self::MODE_MAPFIELD) {
      $this->_rows = [];
    }
    else {
      $this->_activeFieldCount = count($this->_activeFields);
    }

    if ($mode == self::MODE_IMPORT) {
      //get the key of email field
      foreach ($mapper as $key => $value) {
        if (strtolower($value) == 'email') {
          $emailKey = $key;
          break;
        }
      }
    }

    if ($statusID) {
      $this->progressImport($statusID);
      $startTimestamp = $currTimestamp = $prevTimestamp = time();
    }
    // get the contents of the temp. import table
    $query = "SELECT * FROM $tableName";
    if ($mode == self::MODE_IMPORT) {
      $query .= " WHERE $statusFieldName = 'NEW'";
    }

    $result = CRM_Core_DAO::executeQuery($query);

    while ($result->fetch()) {
      $values = array_values($result->toArray());
      $this->_rowCount++;

      /* trim whitespace around the values */
      foreach ($values as $k => $v) {
        $values[$k] = trim($v, " \t\r\n");
      }
      if (CRM_Utils_System::isNull($values)) {
        continue;
      }

      $this->_totalCount++;

      if ($mode == self::MODE_MAPFIELD) {
        $returnCode = $this->mapField($values);
      }
      elseif ($mode == self::MODE_PREVIEW) {
        $returnCode = $this->preview($values);
      }
      elseif ($mode == self::MODE_SUMMARY) {
        $returnCode = $this->summary($values);
      }
      elseif ($mode == self::MODE_IMPORT) {
        //print "Running parser in import mode<br/>\n";
        $returnCode = $this->import($onDuplicate, $values, $doGeocodeAddress);
        if ($statusID && (($this->_rowCount % 50) == 0)) {
          $prevTimestamp = $this->progressImport($statusID, FALSE, $startTimestamp, $prevTimestamp, $totalRowCount);
        }
      }
      else {
        $returnCode = self::ERROR;
      }

      // note that a line could be valid but still produce a warning
      if ($returnCode & self::VALID) {
        $this->_validCount++;
        if ($mode == self::MODE_MAPFIELD) {
          $this->_rows[] = $values;
          $this->_activeFieldCount = max($this->_activeFieldCount, count($values));
        }
      }

      if ($returnCode & self::WARNING) {
        $this->_warningCount++;
        if ($this->_warningCount < $this->_maxWarningCount) {
          $this->_warningCount[] = $line;
        }
      }

      if ($returnCode & self::ERROR) {
        $this->_invalidRowCount++;
        array_unshift($values, $this->_rowCount);
        $this->_errors[] = $values;
      }

      if ($returnCode & self::CONFLICT) {
        $this->_conflictCount++;
        array_unshift($values, $this->_rowCount);
        $this->_conflicts[] = $values;
      }

      if ($returnCode & self::NO_MATCH) {
        $this->_unMatchCount++;
        array_unshift($values, $this->_rowCount);
        $this->_unMatch[] = $values;
      }

      if ($returnCode & self::DUPLICATE) {
        $this->_duplicateCount++;
        array_unshift($values, $this->_rowCount);
        $this->_duplicates[] = $values;
        if ($onDuplicate != self::DUPLICATE_SKIP) {
          $this->_validCount++;
        }
      }

      if ($returnCode & self::UNPARSED_ADDRESS_WARNING) {
        $this->_unparsedAddressCount++;
        array_unshift($values, $this->_rowCount);
        $this->_unparsedAddresses[] = $values;
      }
      // we give the derived class a way of aborting the process
      // note that the return code could be multiple code or'ed together
      if ($returnCode & self::STOP) {
        break;
      }

      // if we are done processing the maxNumber of lines, break
      if ($this->_maxLinesToProcess > 0 && $this->_validCount >= $this->_maxLinesToProcess) {
        break;
      }

      // see if we've hit our timeout yet
      /* if ( $the_thing_with_the_stuff ) {
      do_something( );
      } */
    }

    if ($mode == self::MODE_PREVIEW || $mode == self::MODE_IMPORT) {
      $customHeaders = $mapper;

      $customfields = CRM_Core_BAO_CustomField::getFields($this->_contactType);
      foreach ($customHeaders as $key => $value) {
        if ($id = CRM_Core_BAO_CustomField::getKeyID($value)) {
          $customHeaders[$key] = $customfields[$id][0];
        }
      }

      if ($this->_invalidRowCount) {
        // removed view url for invlaid contacts
        $headers = array_merge([
          ts('Line Number'),
          ts('Reason'),
        ], $customHeaders);
        $this->_errorFileName = self::errorFileName(self::ERROR);
        self::exportCSV($this->_errorFileName, $headers, $this->_errors);
      }
      if ($this->_conflictCount) {
        $headers = array_merge([
          ts('Line Number'),
          ts('Reason'),
        ], $customHeaders);
        $this->_conflictFileName = self::errorFileName(self::CONFLICT);
        self::exportCSV($this->_conflictFileName, $headers, $this->_conflicts);
      }
      if ($this->_duplicateCount) {
        $headers = array_merge([
          ts('Line Number'),
          ts('View Contact URL'),
        ], $customHeaders);

        $this->_duplicateFileName = self::errorFileName(self::DUPLICATE);
        self::exportCSV($this->_duplicateFileName, $headers, $this->_duplicates);
      }
      if ($this->_unMatchCount) {
        $headers = array_merge([
          ts('Line Number'),
          ts('Reason'),
        ], $customHeaders);

        $this->_misMatchFilemName = self::errorFileName(self::NO_MATCH);
        self::exportCSV($this->_misMatchFilemName, $headers, $this->_unMatch);
      }
      if ($this->_unparsedAddressCount) {
        $headers = array_merge([
          ts('Line Number'),
          ts('Contact Edit URL'),
        ], $customHeaders);
        $this->_errorFileName = self::errorFileName(self::UNPARSED_ADDRESS_WARNING);
        self::exportCSV($this->_errorFileName, $headers, $this->_unparsedAddresses);
      }
    }
    //echo "$this->_totalCount,$this->_invalidRowCount,$this->_conflictCount,$this->_duplicateCount";
    return $this->fini();
  }

  /**
   * Given a list of the importable field keys that the user has selected.
   * set the active fields array to this list
   *
   * @param array $fieldKeys
   *   Mapped array of values.
   */
  public function setActiveFields($fieldKeys) {
    $this->_activeFieldCount = count($fieldKeys);
    foreach ($fieldKeys as $key) {
      if (empty($this->_fields[$key])) {
        $this->_activeFields[] = new CRM_Contact_Import_Field('', ts('- do not import -'));
      }
      else {
        $this->_activeFields[] = clone($this->_fields[$key]);
      }
    }
  }

  /**
   * @param $elements
   */
  public function setActiveFieldLocationTypes($elements) {
    for ($i = 0; $i < count($elements); $i++) {
      $this->_activeFields[$i]->_hasLocationType = $elements[$i];
    }
  }

  /**
   * @param $elements
   */

  /**
   * @param $elements
   */
  public function setActiveFieldPhoneTypes($elements) {
    for ($i = 0; $i < count($elements); $i++) {
      $this->_activeFields[$i]->_phoneType = $elements[$i];
    }
  }

  /**
   * @param $elements
   */
  public function setActiveFieldWebsiteTypes($elements) {
    for ($i = 0; $i < count($elements); $i++) {
      $this->_activeFields[$i]->_websiteType = $elements[$i];
    }
  }

  /**
   * Set IM Service Provider type fields.
   *
   * @param array $elements
   *   IM service provider type ids.
   */
  public function setActiveFieldImProviders($elements) {
    for ($i = 0; $i < count($elements); $i++) {
      $this->_activeFields[$i]->_imProvider = $elements[$i];
    }
  }

  /**
   * @param $elements
   */
  public function setActiveFieldRelated($elements) {
    for ($i = 0; $i < count($elements); $i++) {
      $this->_activeFields[$i]->_related = $elements[$i];
    }
  }

  /**
   * @param $elements
   */
  public function setActiveFieldRelatedContactType($elements) {
    for ($i = 0; $i < count($elements); $i++) {
      $this->_activeFields[$i]->_relatedContactType = $elements[$i];
    }
  }

  /**
   * @param $elements
   */
  public function setActiveFieldRelatedContactDetails($elements) {
    for ($i = 0; $i < count($elements); $i++) {
      $this->_activeFields[$i]->_relatedContactDetails = $elements[$i];
    }
  }

  /**
   * @param $elements
   */
  public function setActiveFieldRelatedContactLocType($elements) {
    for ($i = 0; $i < count($elements); $i++) {
      $this->_activeFields[$i]->_relatedContactLocType = $elements[$i];
    }
  }

  /**
   * Set active field for related contact's phone type.
   *
   * @param array $elements
   */
  public function setActiveFieldRelatedContactPhoneType($elements) {
    for ($i = 0; $i < count($elements); $i++) {
      $this->_activeFields[$i]->_relatedContactPhoneType = $elements[$i];
    }
  }

  /**
   * @param $elements
   */
  public function setActiveFieldRelatedContactWebsiteType($elements) {
    for ($i = 0; $i < count($elements); $i++) {
      $this->_activeFields[$i]->_relatedContactWebsiteType = $elements[$i];
    }
  }

  /**
   * Set IM Service Provider type fields for related contacts.
   *
   * @param array $elements
   *   IM service provider type ids of related contact.
   */
  public function setActiveFieldRelatedContactImProvider($elements) {
    for ($i = 0; $i < count($elements); $i++) {
      $this->_activeFields[$i]->_relatedContactImProvider = $elements[$i];
    }
  }

  /**
   * Format the field values for input to the api.
   *
   * @return array
   *   (reference ) associative array of name/value pairs
   */
  public function &getActiveFieldParams() {
    $params = [];

    for ($i = 0; $i < $this->_activeFieldCount; $i++) {
      if ($this->_activeFields[$i]->_name == 'do_not_import') {
        continue;
      }

      if (isset($this->_activeFields[$i]->_value)) {
        if (isset($this->_activeFields[$i]->_hasLocationType)) {
          if (!isset($params[$this->_activeFields[$i]->_name])) {
            $params[$this->_activeFields[$i]->_name] = [];
          }

          $value = [
            $this->_activeFields[$i]->_name => $this->_activeFields[$i]->_value,
            'location_type_id' => $this->_activeFields[$i]->_hasLocationType,
          ];

          if (isset($this->_activeFields[$i]->_phoneType)) {
            $value['phone_type_id'] = $this->_activeFields[$i]->_phoneType;
          }

          // get IM service Provider type id
          if (isset($this->_activeFields[$i]->_imProvider)) {
            $value['provider_id'] = $this->_activeFields[$i]->_imProvider;
          }

          $params[$this->_activeFields[$i]->_name][] = $value;
        }
        elseif (isset($this->_activeFields[$i]->_websiteType)) {
          $value = [
            $this->_activeFields[$i]->_name => $this->_activeFields[$i]->_value,
            'website_type_id' => $this->_activeFields[$i]->_websiteType,
          ];

          $params[$this->_activeFields[$i]->_name][] = $value;
        }

        if (!isset($params[$this->_activeFields[$i]->_name])) {
          if (!isset($this->_activeFields[$i]->_related)) {
            $params[$this->_activeFields[$i]->_name] = $this->_activeFields[$i]->_value;
          }
        }

        //minor fix for CRM-4062
        if (isset($this->_activeFields[$i]->_related)) {
          if (!isset($params[$this->_activeFields[$i]->_related])) {
            $params[$this->_activeFields[$i]->_related] = [];
          }

          if (!isset($params[$this->_activeFields[$i]->_related]['contact_type']) && !empty($this->_activeFields[$i]->_relatedContactType)) {
            $params[$this->_activeFields[$i]->_related]['contact_type'] = $this->_activeFields[$i]->_relatedContactType;
          }

          if (isset($this->_activeFields[$i]->_relatedContactLocType) && !empty($this->_activeFields[$i]->_value)) {
            if (!empty($params[$this->_activeFields[$i]->_related][$this->_activeFields[$i]->_relatedContactDetails]) &&
              !is_array($params[$this->_activeFields[$i]->_related][$this->_activeFields[$i]->_relatedContactDetails])
            ) {
              $params[$this->_activeFields[$i]->_related][$this->_activeFields[$i]->_relatedContactDetails] = [];
            }
            $value = [
              $this->_activeFields[$i]->_relatedContactDetails => $this->_activeFields[$i]->_value,
              'location_type_id' => $this->_activeFields[$i]->_relatedContactLocType,
            ];

            if (isset($this->_activeFields[$i]->_relatedContactPhoneType)) {
              $value['phone_type_id'] = $this->_activeFields[$i]->_relatedContactPhoneType;
            }

            // get IM service Provider type id for related contact
            if (isset($this->_activeFields[$i]->_relatedContactImProvider)) {
              $value['provider_id'] = $this->_activeFields[$i]->_relatedContactImProvider;
            }

            $params[$this->_activeFields[$i]->_related][$this->_activeFields[$i]->_relatedContactDetails][] = $value;
          }
          elseif (isset($this->_activeFields[$i]->_relatedContactWebsiteType)) {
            $params[$this->_activeFields[$i]->_related][$this->_activeFields[$i]->_relatedContactDetails][] = [
              'url' => $this->_activeFields[$i]->_value,
              'website_type_id' => $this->_activeFields[$i]->_relatedContactWebsiteType,
            ];
          }
          elseif (empty($this->_activeFields[$i]->_value) && isset($this->_activeFields[$i]->_relatedContactLocType)) {
            if (empty($params[$this->_activeFields[$i]->_related][$this->_activeFields[$i]->_relatedContactDetails])) {
              $params[$this->_activeFields[$i]->_related][$this->_activeFields[$i]->_relatedContactDetails] = [];
            }
          }
          else {
            $params[$this->_activeFields[$i]->_related][$this->_activeFields[$i]->_relatedContactDetails] = $this->_activeFields[$i]->_value;
          }
        }
      }
    }

    return $params;
  }

  /**
   * @param string $name
   * @param $title
   * @param int $type
   * @param string $headerPattern
   * @param string $dataPattern
   * @param bool $hasLocationType
   */
  public function addField(
    $name, $title, $type = CRM_Utils_Type::T_INT,
    $headerPattern = '//', $dataPattern = '//',
    $hasLocationType = FALSE
  ) {
    $this->_fields[$name] = new CRM_Contact_Import_Field($name, $title, $type, $headerPattern, $dataPattern, $hasLocationType);
    if (empty($name)) {
      $this->_fields['doNotImport'] = new CRM_Contact_Import_Field($name, $title, $type, $headerPattern, $dataPattern, $hasLocationType);
    }
  }

  /**
   * Store parser values.
   *
   * @param CRM_Core_Session $store
   *
   * @param int $mode
   */
  public function set($store, $mode = self::MODE_SUMMARY) {
    $store->set('rowCount', $this->_rowCount);
    $store->set('fields', $this->getSelectValues());
    $store->set('fieldTypes', $this->getSelectTypes());

    $store->set('columnCount', $this->_activeFieldCount);

    $store->set('totalRowCount', $this->_totalCount);
    $store->set('validRowCount', $this->_validCount);
    $store->set('invalidRowCount', $this->_invalidRowCount);
    $store->set('conflictRowCount', $this->_conflictCount);
    $store->set('unMatchCount', $this->_unMatchCount);

    switch ($this->_contactType) {
      case 'Individual':
        $store->set('contactType', CRM_Import_Parser::CONTACT_INDIVIDUAL);
        break;

      case 'Household':
        $store->set('contactType', CRM_Import_Parser::CONTACT_HOUSEHOLD);
        break;

      case 'Organization':
        $store->set('contactType', CRM_Import_Parser::CONTACT_ORGANIZATION);
    }

    if ($this->_invalidRowCount) {
      $store->set('errorsFileName', $this->_errorFileName);
    }
    if ($this->_conflictCount) {
      $store->set('conflictsFileName', $this->_conflictFileName);
    }
    if (isset($this->_rows) && !empty($this->_rows)) {
      $store->set('dataValues', $this->_rows);
    }

    if ($this->_unMatchCount) {
      $store->set('mismatchFileName', $this->_misMatchFilemName);
    }

    if ($mode == self::MODE_IMPORT) {
      $store->set('duplicateRowCount', $this->_duplicateCount);
      $store->set('unparsedAddressCount', $this->_unparsedAddressCount);
      if ($this->_duplicateCount) {
        $store->set('duplicatesFileName', $this->_duplicateFileName);
      }
      if ($this->_unparsedAddressCount) {
        $store->set('errorsFileName', $this->_errorFileName);
      }
    }
    //echo "$this->_totalCount,$this->_invalidRowCount,$this->_conflictCount,$this->_duplicateCount";
  }

  /**
   * Export data to a CSV file.
   *
   * @param string $fileName
   * @param array $header
   * @param array $data
   */
  public static function exportCSV($fileName, $header, $data) {

    if (file_exists($fileName) && !is_writable($fileName)) {
      CRM_Core_Error::movedSiteError($fileName);
    }
    //hack to remove '_status', '_statusMsg' and '_id' from error file
    $errorValues = [];
    $dbRecordStatus = ['IMPORTED', 'ERROR', 'DUPLICATE', 'INVALID', 'NEW'];
    foreach ($data as $rowCount => $rowValues) {
      $count = 0;
      foreach ($rowValues as $key => $val) {
        if (in_array($val, $dbRecordStatus) && $count == (count($rowValues) - 3)) {
          break;
        }
        $errorValues[$rowCount][$key] = $val;
        $count++;
      }
    }
    $data = $errorValues;

    $output = [];
    $fd = fopen($fileName, 'w');

    foreach ($header as $key => $value) {
      $header[$key] = "\"$value\"";
    }
    $config = CRM_Core_Config::singleton();
    $output[] = implode($config->fieldSeparator, $header);

    foreach ($data as $datum) {
      foreach ($datum as $key => $value) {
        $datum[$key] = "\"$value\"";
      }
      $output[] = implode($config->fieldSeparator, $datum);
    }
    fwrite($fd, implode("\n", $output));
    fclose($fd);
  }

  /**
   * Update the record with PK $id in the import database table.
   *
   * @deprecated - call setImportStatus directly as the parameters are simpler,
   *
   * @param int $id
   * @param array $params
   */
  public function updateImportRecord($id, $params): void {
    $this->setImportStatus((int) $id, $params[$this->_statusFieldName] ?? '', $params["{$this->_statusFieldName}Msg"] ?? '');
  }

  /**
   * Set the import status for the given record.
   *
   * If this is a sql import then the sql table will be used and the update
   * will not happen as the relevant fields don't exist in the table - hence
   * the checks that statusField & primary key are set.
   *
   * @param int $id
   * @param string $status
   * @param string $message
   */
  public function setImportStatus(int $id, string $status, string $message): void {
    if ($this->_statusFieldName && $this->_primaryKeyName) {
      CRM_Core_DAO::executeQuery("
        UPDATE $this->_tableName
        SET $this->_statusFieldName = %1,
          {$this->_statusFieldName}Msg = %2
        WHERE  $this->_primaryKeyName = %3
      ", [
        1 => [$status, 'String'],
        2 => [$message, 'String'],
        3 => [$id, 'Integer'],
      ]);
    }
  }

  /**
   * Format contact parameters.
   *
   * @todo this function needs re-writing & re-merging into the main function.
   *
   * Here be dragons.
   *
   * @param array $values
   * @param array $params
   *
   * @return bool
   */
  protected function formatContactParameters(&$values, &$params) {
    // Crawl through the possible classes:
    // Contact
    //      Individual
    //      Household
    //      Organization
    //          Location
    //              Address
    //              Email
    //              Phone
    //              IM
    //      Note
    //      Custom

    // first add core contact values since for other Civi modules they are not added
    $contactFields = CRM_Contact_DAO_Contact::fields();
    _civicrm_api3_store_values($contactFields, $values, $params);

    if (isset($values['contact_type'])) {
      // we're an individual/household/org property

      $fields[$values['contact_type']] = CRM_Contact_DAO_Contact::fields();

      _civicrm_api3_store_values($fields[$values['contact_type']], $values, $params);
      return TRUE;
    }

    // Cache the various object fields
    // @todo - remove this after confirming this is just a compilation of other-wise-cached fields.
    static $fields = [];

    if (isset($values['individual_prefix'])) {
      if (!empty($params['prefix_id'])) {
        $prefixes = CRM_Core_PseudoConstant::get('CRM_Contact_DAO_Contact', 'prefix_id');
        $params['prefix'] = $prefixes[$params['prefix_id']];
      }
      else {
        $params['prefix'] = $values['individual_prefix'];
      }
      return TRUE;
    }

    if (isset($values['individual_suffix'])) {
      if (!empty($params['suffix_id'])) {
        $suffixes = CRM_Core_PseudoConstant::get('CRM_Contact_DAO_Contact', 'suffix_id');
        $params['suffix'] = $suffixes[$params['suffix_id']];
      }
      else {
        $params['suffix'] = $values['individual_suffix'];
      }
      return TRUE;
    }

    // CRM-4575
    if (isset($values['email_greeting'])) {
      if (!empty($params['email_greeting_id'])) {
        $emailGreetingFilter = [
          'contact_type' => $params['contact_type'] ?? NULL,
          'greeting_type' => 'email_greeting',
        ];
        $emailGreetings = CRM_Core_PseudoConstant::greeting($emailGreetingFilter);
        $params['email_greeting'] = $emailGreetings[$params['email_greeting_id']];
      }
      else {
        $params['email_greeting'] = $values['email_greeting'];
      }

      return TRUE;
    }

    if (isset($values['postal_greeting'])) {
      if (!empty($params['postal_greeting_id'])) {
        $postalGreetingFilter = [
          'contact_type' => $params['contact_type'] ?? NULL,
          'greeting_type' => 'postal_greeting',
        ];
        $postalGreetings = CRM_Core_PseudoConstant::greeting($postalGreetingFilter);
        $params['postal_greeting'] = $postalGreetings[$params['postal_greeting_id']];
      }
      else {
        $params['postal_greeting'] = $values['postal_greeting'];
      }
      return TRUE;
    }

    if (isset($values['addressee'])) {
      $params['addressee'] = $values['addressee'];
      return TRUE;
    }

    if (isset($values['gender'])) {
      if (!empty($params['gender_id'])) {
        $genders = CRM_Core_PseudoConstant::get('CRM_Contact_DAO_Contact', 'gender_id');
        $params['gender'] = $genders[$params['gender_id']];
      }
      else {
        $params['gender'] = $values['gender'];
      }
      return TRUE;
    }

    if (!empty($values['preferred_communication_method'])) {
      $comm = [];
      $pcm = array_change_key_case(array_flip(CRM_Core_PseudoConstant::get('CRM_Contact_DAO_Contact', 'preferred_communication_method')), CASE_LOWER);

      $preffComm = explode(',', $values['preferred_communication_method']);
      foreach ($preffComm as $v) {
        $v = strtolower(trim($v));
        if (array_key_exists($v, $pcm)) {
          $comm[$pcm[$v]] = 1;
        }
      }

      $params['preferred_communication_method'] = $comm;
      return TRUE;
    }

    // format the website params.
    if (!empty($values['url'])) {
      static $websiteFields;
      if (!is_array($websiteFields)) {
        $websiteFields = CRM_Core_DAO_Website::fields();
      }
      if (!array_key_exists('website', $params) ||
        !is_array($params['website'])
      ) {
        $params['website'] = [];
      }

      $websiteCount = count($params['website']);
      _civicrm_api3_store_values($websiteFields, $values,
        $params['website'][++$websiteCount]
      );

      return TRUE;
    }

    if (isset($values['note'])) {
      // add a note field
      if (!isset($params['note'])) {
        $params['note'] = [];
      }
      $noteBlock = count($params['note']) + 1;

      $params['note'][$noteBlock] = [];
      if (!isset($fields['Note'])) {
        $fields['Note'] = CRM_Core_DAO_Note::fields();
      }

      // get the current logged in civicrm user
      $session = CRM_Core_Session::singleton();
      $userID = $session->get('userID');

      if ($userID) {
        $values['contact_id'] = $userID;
      }

      _civicrm_api3_store_values($fields['Note'], $values, $params['note'][$noteBlock]);

      return TRUE;
    }

    // Check for custom field values
    $customFields = CRM_Core_BAO_CustomField::getFields(CRM_Utils_Array::value('contact_type', $values),
      FALSE, FALSE, NULL, NULL, FALSE, FALSE, FALSE
    );

    foreach ($values as $key => $value) {
      if ($customFieldID = CRM_Core_BAO_CustomField::getKeyID($key)) {
        // check if it's a valid custom field id

        if (!array_key_exists($customFieldID, $customFields)) {
          return civicrm_api3_create_error('Invalid custom field ID');
        }
        else {
          $params[$key] = $value;
        }
      }
    }
    return TRUE;
  }

  /**
   * Format location block ready for importing.
   *
   * There is some test coverage for this in CRM_Contact_Import_Parser_ContactTest
   * e.g. testImportPrimaryAddress.
   *
   * @param array $values
   * @param array $params
   *
   * @return bool
   */
  protected function formatLocationBlock(&$values, &$params) {
    $blockTypes = [
      'phone' => 'Phone',
      'email' => 'Email',
      'im' => 'IM',
      'openid' => 'OpenID',
      'phone_ext' => 'Phone',
    ];
    foreach ($blockTypes as $blockFieldName => $block) {
      if (!array_key_exists($blockFieldName, $values)) {
        continue;
      }
      $blockIndex = $values['location_type_id'] . (!empty($values['phone_type_id']) ? '_' . $values['phone_type_id'] : '');

      // block present in value array.
      if (!array_key_exists($blockFieldName, $params) || !is_array($params[$blockFieldName])) {
        $params[$blockFieldName] = [];
      }

      $fields[$block] = $this->getMetadataForEntity($block);

      // copy value to dao field name.
      if ($blockFieldName == 'im') {
        $values['name'] = $values[$blockFieldName];
      }

      _civicrm_api3_store_values($fields[$block], $values,
        $params[$blockFieldName][$blockIndex]
      );

      $this->fillPrimary($params[$blockFieldName][$blockIndex], $values, $block, CRM_Utils_Array::value('id', $params));

      if (empty($params['id']) && (count($params[$blockFieldName]) == 1)) {
        $params[$blockFieldName][$blockIndex]['is_primary'] = TRUE;
      }

      // we only process single block at a time.
      return TRUE;
    }

    // handle address fields.
    if (!array_key_exists('address', $params) || !is_array($params['address'])) {
      $params['address'] = [];
    }

    // Note: we doing multiple value formatting here for address custom fields, plus putting into right format.
    // The actual formatting (like date, country ..etc) for address custom fields is taken care of while saving
    // the address in CRM_Core_BAO_Address::create method
    if (!empty($values['location_type_id'])) {
      static $customFields = [];
      if (empty($customFields)) {
        $customFields = CRM_Core_BAO_CustomField::getFields('Address');
      }
      // make a copy of values, as we going to make changes
      $newValues = $values;
      foreach ($values as $key => $val) {
        $customFieldID = CRM_Core_BAO_CustomField::getKeyID($key);
        if ($customFieldID && array_key_exists($customFieldID, $customFields)) {

          $htmlType = $customFields[$customFieldID]['html_type'] ?? NULL;
          if (CRM_Core_BAO_CustomField::isSerialized($customFields[$customFieldID]) && $val) {
            $mulValues = explode(',', $val);
            $customOption = CRM_Core_BAO_CustomOption::getCustomOption($customFieldID, TRUE);
            $newValues[$key] = [];
            foreach ($mulValues as $v1) {
              foreach ($customOption as $v2) {
                if ((strtolower($v2['label']) == strtolower(trim($v1))) ||
                  (strtolower($v2['value']) == strtolower(trim($v1)))
                ) {
                  if ($htmlType == 'CheckBox') {
                    $newValues[$key][$v2['value']] = 1;
                  }
                  else {
                    $newValues[$key][] = $v2['value'];
                  }
                }
              }
            }
          }
        }
      }
      // consider new values
      $values = $newValues;
    }

    $fields['Address'] = $this->getMetadataForEntity('Address');
    // @todo this is kinda replicated below....
    _civicrm_api3_store_values($fields['Address'], $values, $params['address'][$values['location_type_id']]);

    $addressFields = [
      'county',
      'country',
      'state_province',
      'supplemental_address_1',
      'supplemental_address_2',
      'supplemental_address_3',
      'StateProvince.name',
    ];
    foreach (array_keys($customFields) as $customFieldID) {
      $addressFields[] = 'custom_' . $customFieldID;
    }

    foreach ($addressFields as $field) {
      if (array_key_exists($field, $values)) {
        if (!array_key_exists('address', $params)) {
          $params['address'] = [];
        }
        $params['address'][$values['location_type_id']][$field] = $values[$field];
      }
    }

    $this->fillPrimary($params['address'][$values['location_type_id']], $values, 'address', CRM_Utils_Array::value('id', $params));
    return TRUE;
  }

  /**
   * Get the field metadata for the relevant entity.
   *
   * @param string $entity
   *
   * @return array
   */
  protected function getMetadataForEntity($entity) {
    if (!isset($this->fieldMetadata[$entity])) {
      $className = "CRM_Core_DAO_$entity";
      $this->fieldMetadata[$entity] = $className::fields();
    }
    return $this->fieldMetadata[$entity];
  }

  /**
   * Fill in the primary location.
   *
   * If the contact has a primary address we update it. Otherwise
   * we add an address of the default location type.
   *
   * @param array $params
   *   Address block parameters
   * @param array $values
   *   Input values
   * @param string $entity
   *  - address, email, phone
   * @param int|null $contactID
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function fillPrimary(&$params, $values, $entity, $contactID) {
    if ($values['location_type_id'] === 'Primary') {
      if ($contactID) {
        $primary = civicrm_api3($entity, 'get', [
          'return' => 'location_type_id',
          'contact_id' => $contactID,
          'is_primary' => 1,
          'sequential' => 1,
        ]);
      }
      $defaultLocationType = CRM_Core_BAO_LocationType::getDefault();
      $params['location_type_id'] = (int) (isset($primary) && $primary['count']) ? $primary['values'][0]['location_type_id'] : $defaultLocationType->id;
      $params['is_primary'] = 1;
    }
  }

}
