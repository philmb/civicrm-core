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
 * This class previews the uploaded file and returns summary
 * statistics
 */
class CRM_Member_Import_Form_Preview extends CRM_Import_Form_Preview {

  /**
   * Set variables up before form is built.
   *
   * @return void
   */
  public function preProcess() {
    parent::preProcess();
    //get the data from the session
    $dataValues = $this->get('dataValues');
    $mapper = $this->get('mapper');
    $invalidRowCount = $this->get('invalidRowCount');
    $conflictRowCount = $this->get('conflictRowCount');
    $mismatchCount = $this->get('unMatchCount');

    //get the mapping name displayed if the mappingId is set
    $mappingId = $this->get('loadMappingId');
    if ($mappingId) {
      $mapDAO = new CRM_Core_DAO_Mapping();
      $mapDAO->id = $mappingId;
      $mapDAO->find(TRUE);
    }
    $this->assign('savedMappingName', $mappingId ? $mapDAO->name : NULL);

    if ($invalidRowCount) {
      $urlParams = 'type=' . CRM_Import_Parser::ERROR . '&parser=CRM_Member_Import_Parser_Membership';
      $this->set('downloadErrorRecordsUrl', CRM_Utils_System::url('civicrm/export', $urlParams));
    }

    if ($conflictRowCount) {
      $urlParams = 'type=' . CRM_Import_Parser::CONFLICT . '&parser=CRM_Member_Import_Parser_Membership';
      $this->set('downloadConflictRecordsUrl', CRM_Utils_System::url('civicrm/export', $urlParams));
    }

    if ($mismatchCount) {
      $urlParams = 'type=' . CRM_Import_Parser::NO_MATCH . '&parser=CRM_Member_Import_Parser_Membership';
      $this->set('downloadMismatchRecordsUrl', CRM_Utils_System::url('civicrm/export', $urlParams));
    }

    $properties = [
      'mapper',
      'dataValues',
      'columnCount',
      'totalRowCount',
      'validRowCount',
      'invalidRowCount',
      'conflictRowCount',
      'downloadErrorRecordsUrl',
      'downloadConflictRecordsUrl',
      'downloadMismatchRecordsUrl',
    ];
    $this->setStatusUrl();

    foreach ($properties as $property) {
      $this->assign($property, $this->get($property));
    }
  }

  /**
   * Process the mapped fields and map it into the uploaded file
   * preview the file and extract some summary statistics
   *
   * @return void
   */
  public function postProcess() {
    $fileName = $this->controller->exportValue('DataSource', 'uploadFile');
    $separator = $this->controller->exportValue('DataSource', 'fieldSeparator');
    $invalidRowCount = $this->get('invalidRowCount');
    $conflictRowCount = $this->get('conflictRowCount');
    $onDuplicate = $this->get('onDuplicate');

    $mapper = $this->controller->exportValue('MapField', 'mapper');
    $mapperKeys = [];
    $mapperLocType = [];
    $mapperPhoneType = [];
    // Note: we keep the multi-dimension array (even thought it's not
    // needed in the case of memberships import) so that we can merge
    // the common code with contacts import later and subclass contact
    // and membership imports from there
    foreach ($mapper as $key => $value) {
      $mapperKeys[$key] = $mapper[$key][0];

      if (!empty($mapper[$key][1]) && is_numeric($mapper[$key][1])) {
        $mapperLocType[$key] = $mapper[$key][1];
      }
      else {
        $mapperLocType[$key] = NULL;
      }

      if (!empty($mapper[$key][2]) && (!is_numeric($mapper[$key][2]))) {
        $mapperPhoneType[$key] = $mapper[$key][2];
      }
      else {
        $mapperPhoneType[$key] = NULL;
      }
    }

    $parser = new CRM_Member_Import_Parser_Membership($mapperKeys, $mapperLocType, $mapperPhoneType);

    $mapFields = $this->get('fields');

    foreach ($mapper as $key => $value) {
      $header = [];
      if (isset($mapFields[$mapper[$key][0]])) {
        $header[] = $mapFields[$mapper[$key][0]];
      }
      $mapperFields[] = implode(' - ', $header);
    }
    $parser->run($fileName, $separator,
      $mapperFields,
      $this->getSubmittedValue('skipColumnHeader'),
      CRM_Import_Parser::MODE_IMPORT,
      $this->get('contactType'),
      $onDuplicate,
      $this->get('statusID'),
      $this->get('totalRowCount')
    );

    // add all the necessary variables to the form
    $parser->set($this, CRM_Import_Parser::MODE_IMPORT);

    // check if there is any error occurred
    $errorStack = CRM_Core_Error::singleton();
    $errors = $errorStack->getErrors();
    $errorMessage = [];

    if (is_array($errors)) {
      foreach ($errors as $key => $value) {
        $errorMessage[] = $value['message'];
      }

      $errorFile = $fileName['name'] . '.error.log';

      if ($fd = fopen($errorFile, 'w')) {
        fwrite($fd, implode('\n', $errorMessage));
      }
      fclose($fd);

      $this->set('errorFile', $errorFile);
      $urlParams = 'type=' . CRM_Import_Parser::ERROR . '&parser=CRM_Member_Import_Parser_Membership';
      $this->set('downloadErrorRecordsUrl', CRM_Utils_System::url('civicrm/export', $urlParams));
      $urlParams = 'type=' . CRM_Import_Parser::CONFLICT . '&parser=CRM_Member_Import_Parser_Membership';
      $this->set('downloadConflictRecordsUrl', CRM_Utils_System::url('civicrm/export', $urlParams));
      $urlParams = 'type=' . CRM_Import_Parser::NO_MATCH . '&parser=CRM_Member_Import_Parser_Membership';
      $this->set('downloadMismatchRecordsUrl', CRM_Utils_System::url('civicrm/export', $urlParams));
    }
  }

}
