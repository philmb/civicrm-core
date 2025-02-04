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

/**
 * This class delegates to the chosen DataSource to grab the data to be imported.
 */
class CRM_Contact_Import_Form_DataSource extends CRM_Core_Form {

  private $_dataSource;

  private $_dataSourceIsValid = FALSE;

  private $_dataSourceClassFile;

  private $_dataSourceClass;

  /**
   * Get any smarty elements that may not be present in the form.
   *
   * To make life simpler for smarty we ensure they are set to null
   * rather than unset. This is done at the last minute when $this
   * is converted to an array to be assigned to the form.
   *
   * @return array
   */
  public function getOptionalQuickFormElements(): array {
    return ['disableUSPS'];
  }

  /**
   * Set variables up before form is built.
   *
   * @throws \CRM_Core_Exception
   */
  public function preProcess() {
    $results = [];
    $config = CRM_Core_Config::singleton();
    $handler = opendir($config->uploadDir);
    $errorFiles = ['sqlImport.errors', 'sqlImport.conflicts', 'sqlImport.duplicates', 'sqlImport.mismatch'];

    // check for post max size avoid when called twice
    $snippet = $_GET['snippet'] ?? 0;
    if (empty($snippet)) {
      CRM_Utils_Number::formatUnitSize(ini_get('post_max_size'), TRUE);
    }

    while ($file = readdir($handler)) {
      if ($file != '.' && $file != '..' &&
        in_array($file, $errorFiles) && !is_writable($config->uploadDir . $file)
      ) {
        $results[] = $file;
      }
    }
    closedir($handler);
    if (!empty($results)) {
      $this->invalidConfig(ts('<b>%1</b> file(s) in %2 directory are not writable. Listed file(s) might be used during the import to log the errors occurred during Import process. Contact your site administrator for assistance.', [
        1 => implode(', ', $results),
        2 => $config->uploadDir,
      ]));
    }

    $this->_dataSourceIsValid = FALSE;
    $this->_dataSource = CRM_Utils_Request::retrieveValue(
      'dataSource',
      'String',
      NULL,
      FALSE,
      'GET'
    );

    $this->_params = $this->controller->exportValues($this->_name);
    if (!$this->_dataSource) {
      //considering dataSource as base criteria instead of hidden_dataSource.
      $this->_dataSource = CRM_Utils_Array::value('dataSource',
        $_POST,
        CRM_Utils_Array::value('dataSource',
          $this->_params
        )
      );
      $this->assign('showOnlyDataSourceFormPane', FALSE);
    }
    else {
      $this->assign('showOnlyDataSourceFormPane', TRUE);
    }

    $dataSources = $this->_getDataSources();
    if ($this->_dataSource && isset($dataSources[$this->_dataSource])) {
      $this->_dataSourceIsValid = TRUE;
      $this->assign('showDataSourceFormPane', TRUE);
      $dataSourcePath = explode('_', $this->_dataSource);
      $templateFile = 'CRM/Contact/Import/Form/' . $dataSourcePath[3] . ".tpl";
    }
    elseif ($this->_dataSource) {
      $this->invalidConfig('Invalid data source');
    }
    $this->assign('dataSourceFormTemplateFile', $templateFile ?? NULL);
  }

  /**
   * Build the form object.
   */
  public function buildQuickForm() {

    // If there's a dataSource in the query string, we need to load
    // the form from the chosen DataSource class
    if ($this->_dataSourceIsValid) {
      $this->_dataSourceClassFile = str_replace('_', '/', $this->_dataSource) . ".php";
      require_once $this->_dataSourceClassFile;
      $this->_dataSourceClass = new $this->_dataSource();
      $this->_dataSourceClass->buildQuickForm($this);
    }

    // Get list of data sources and display them as options
    $dataSources = $this->_getDataSources();

    $this->assign('urlPath', "civicrm/import");
    $this->assign('urlPathVar', 'snippet=4');

    $this->add('select', 'dataSource', ts('Data Source'), $dataSources, TRUE,
      ['onchange' => 'buildDataSourceFormBlock(this.value);']
    );

    // duplicate handling options
    $this->addRadio('onDuplicate', ts('For Duplicate Contacts'), [
      CRM_Import_Parser::DUPLICATE_SKIP => ts('Skip'),
      CRM_Import_Parser::DUPLICATE_UPDATE => ts('Update'),
      CRM_Import_Parser::DUPLICATE_FILL => ts('Fill'),
      CRM_Import_Parser::DUPLICATE_NOCHECK => ts('No Duplicate Checking'),
    ]);

    $mappingArray = CRM_Core_BAO_Mapping::getMappings('Import Contact');

    $this->assign('savedMapping', $mappingArray);
    $this->addElement('select', 'savedMapping', ts('Saved Field Mapping'), ['' => ts('- select -')] + $mappingArray);

    $js = ['onClick' => "buildSubTypes();buildDedupeRules();"];
    // contact types option
    $contactTypeOptions = $contactTypeAttributes = [];
    if (CRM_Contact_BAO_ContactType::isActive('Individual')) {
      $contactTypeOptions[CRM_Import_Parser::CONTACT_INDIVIDUAL] = ts('Individual');
      $contactTypeAttributes[CRM_Import_Parser::CONTACT_INDIVIDUAL] = $js;
    }
    if (CRM_Contact_BAO_ContactType::isActive('Household')) {
      $contactTypeOptions[CRM_Import_Parser::CONTACT_HOUSEHOLD] = ts('Household');
      $contactTypeAttributes[CRM_Import_Parser::CONTACT_HOUSEHOLD] = $js;
    }
    if (CRM_Contact_BAO_ContactType::isActive('Organization')) {
      $contactTypeOptions[CRM_Import_Parser::CONTACT_ORGANIZATION] = ts('Organization');
      $contactTypeAttributes[CRM_Import_Parser::CONTACT_ORGANIZATION] = $js;
    }
    $this->addRadio('contactType', ts('Contact Type'), $contactTypeOptions, [], NULL, FALSE, $contactTypeAttributes);

    $this->addElement('select', 'subType', ts('Subtype'));
    $this->addElement('select', 'dedupe', ts('Dedupe Rule'));

    CRM_Core_Form_Date::buildAllowedDateFormats($this);

    $config = CRM_Core_Config::singleton();
    $geoCode = FALSE;
    if (CRM_Utils_GeocodeProvider::getUsableClassName()) {
      $geoCode = TRUE;
      $this->addElement('checkbox', 'doGeocodeAddress', ts('Geocode addresses during import?'));
    }
    $this->assign('geoCode', $geoCode);

    $this->addElement('text', 'fieldSeparator', ts('Import Field Separator'), ['size' => 2]);

    if (Civi::settings()->get('address_standardization_provider') === 'USPS') {
      $this->addElement('checkbox', 'disableUSPS', ts('Disable USPS address validation during import?'));
    }

    $this->addButtons([
      [
        'type' => 'upload',
        'name' => ts('Continue'),
        'spacing' => '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;',
        'isDefault' => TRUE,
      ],
      [
        'type' => 'cancel',
        'name' => ts('Cancel'),
      ],
    ]);
  }

  /**
   * Set the default values of various form elements.
   *
   * access        public
   *
   * @return array
   *   reference to the array of default values
   */
  public function setDefaultValues() {
    $config = CRM_Core_Config::singleton();
    $defaults = [
      'dataSource' => 'CRM_Import_DataSource_CSV',
      'onDuplicate' => CRM_Import_Parser::DUPLICATE_SKIP,
      'contactType' => CRM_Import_Parser::CONTACT_INDIVIDUAL,
      'fieldSeparator' => $config->fieldSeparator,
    ];

    if ($this->get('loadedMapping')) {
      $defaults['savedMapping'] = $this->get('loadedMapping');
    }

    return $defaults;
  }

  /**
   * @return array
   * @throws Exception
   */
  private function _getDataSources() {
    // Hmm... file-system scanners don't really belong in forms...
    if (isset(Civi::$statics[__CLASS__]['datasources'])) {
      return Civi::$statics[__CLASS__]['datasources'];
    }

    // Open the data source dir and scan it for class files
    global $civicrm_root;
    $dataSourceDir = $civicrm_root . DIRECTORY_SEPARATOR . 'CRM' . DIRECTORY_SEPARATOR . 'Import' . DIRECTORY_SEPARATOR . 'DataSource' . DIRECTORY_SEPARATOR;
    $dataSources = [];
    if (!is_dir($dataSourceDir)) {
      $this->invalidConfig("Import DataSource directory $dataSourceDir does not exist");
    }
    if (!$dataSourceHandle = opendir($dataSourceDir)) {
      $this->invalidConfig("Unable to access DataSource directory $dataSourceDir");
    }

    while (($dataSourceFile = readdir($dataSourceHandle)) !== FALSE) {
      $fileType = filetype($dataSourceDir . $dataSourceFile);
      $matches = [];
      if (($fileType === 'file' || $fileType === 'link') &&
        preg_match('/^(.+)\.php$/', $dataSourceFile, $matches)
      ) {
        $dataSourceClass = "CRM_Import_DataSource_" . $matches[1];
        require_once $dataSourceDir . DIRECTORY_SEPARATOR . $dataSourceFile;
        $object = new $dataSourceClass();
        $info = $object->getInfo();
        if ($object->checkPermission()) {
          $dataSources[$dataSourceClass] = $info['title'];
        }
      }
    }
    closedir($dataSourceHandle);

    Civi::$statics[__CLASS__]['datasources'] = $dataSources;
    return $dataSources;
  }

  /**
   * Call the DataSource's postProcess method.
   */
  public function postProcess() {
    $this->controller->resetPage('MapField');

    if ($this->_dataSourceIsValid) {
      // Setup the params array
      $this->_params = $this->controller->exportValues($this->_name);

      $storeParams = [
        'onDuplicate' => $this->exportValue('onDuplicate'),
        'dedupe' => $this->exportValue('dedupe'),
        'contactType' => $this->exportValue('contactType'),
        'contactSubType' => $this->exportValue('subType'),
        'dateFormats' => $this->exportValue('dateFormats'),
        'savedMapping' => $this->exportValue('savedMapping'),
      ];

      foreach ($storeParams as $storeName => $value) {
        $this->set($storeName, $value);
      }
      $this->set('disableUSPS', !empty($this->_params['disableUSPS']));

      $this->set('dataSource', $this->_params['dataSource']);
      $this->set('skipColumnHeader', CRM_Utils_Array::value('skipColumnHeader', $this->_params));

      CRM_Core_Session::singleton()->set('dateTypes', $storeParams['dateFormats']);

      // Get the PEAR::DB object
      $dao = new CRM_Core_DAO();
      $db = $dao->getDatabaseConnection();

      //hack to prevent multiple tables.
      $this->_params['import_table_name'] = $this->get('importTableName');
      if (!$this->_params['import_table_name']) {
        $this->_params['import_table_name'] = 'civicrm_import_job_' . md5(uniqid(rand(), TRUE));
      }

      $this->_dataSourceClass->postProcess($this->_params, $db, $this);

      // We should have the data in the DB now, parse it
      $importTableName = $this->get('importTableName');
      $fieldNames = $this->_prepareImportTable($db, $importTableName);
      $mapper = [];

      $parser = new CRM_Contact_Import_Parser_Contact($mapper);
      $parser->setMaxLinesToProcess(100);
      $parser->run($importTableName,
        $mapper,
        CRM_Import_Parser::MODE_MAPFIELD,
        $storeParams['contactType'],
        $fieldNames['pk'],
        $fieldNames['status'],
        CRM_Import_Parser::DUPLICATE_SKIP,
        NULL, NULL, FALSE,
        CRM_Contact_Import_Parser::DEFAULT_TIMEOUT,
        $storeParams['contactSubType'],
        $storeParams['dedupe']
      );

      // add all the necessary variables to the form
      $parser->set($this);
    }
    else {
      $this->invalidConfig("Invalid DataSource on form post. This shouldn't happen!");
    }
  }

  /**
   * Add a PK and status column to the import table so we can track our progress.
   * Returns the name of the primary key and status columns
   *
   * @param $db
   * @param string $importTableName
   *
   * @return array
   */
  private function _prepareImportTable($db, $importTableName) {
    /* TODO: Add a check for an existing _status field;
     *  if it exists, create __status instead and return that
     */

    $statusFieldName = '_status';
    $primaryKeyName = '_id';

    $this->set('primaryKeyName', $primaryKeyName);
    $this->set('statusFieldName', $statusFieldName);

    /* Make sure the PK is always last! We rely on this later.
     * Should probably stop doing that at some point, but it
     * would require moving to associative arrays rather than
     * relying on numerical order of the fields. This could in
     * turn complicate matters for some DataSources, which
     * would also not be good. Decisions, decisions...
     */

    $alterQuery = "ALTER TABLE $importTableName
                       ADD COLUMN $statusFieldName VARCHAR(32)
                            DEFAULT 'NEW' NOT NULL,
                       ADD COLUMN ${statusFieldName}Msg TEXT,
                       ADD COLUMN $primaryKeyName INT PRIMARY KEY NOT NULL
                               AUTO_INCREMENT";
    $db->query($alterQuery);

    return ['status' => $statusFieldName, 'pk' => $primaryKeyName];
  }

  /**
   * General function for handling invalid configuration.
   *
   * I was going to statusBounce them all but when I tested I was 'bouncing' to weird places
   * whereas throwing an exception gave no behaviour change. So, I decided to centralise
   * and we can 'flip the switch' later.
   *
   * @param $message
   *
   * @throws \CRM_Core_Exception
   */
  protected function invalidConfig($message) {
    throw new CRM_Core_Exception($message);
  }

  /**
   * Return a descriptive name for the page, used in wizard header
   *
   * @return string
   */
  public function getTitle(): string {
    return ts('Choose Data Source');
  }

}
