<?php

namespace Vanderbilt\HideIdentifiersExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use mysql_xdevapi\Exception;
use REDCap;

class HideIdentifiersExternalModule extends AbstractExternalModule
{
    function redcap_every_page_top($project_id) {
        if ($this->userDeIdentified($project_id,USERID)) {
            if (isset($project_id)) {
                $currentProject = new \Project($project_id);
                $metaData = $currentProject->metadata;
                $fieldsOnForm = array_keys($currentProject->metadata);
                $removeFields = $this->getProjectSetting('remove-fields');

                $customRecordLabel = $this->hasCustomRecordLabel($project_id);

                $phiFields = array();
                foreach ($fieldsOnForm as $field) {
                    if ($metaData[$field]['field_phi'] == 1) {
                        $phiFields[] = $field;
                    }
                }

                $phiColumns = array();
                if ($_GET['report_id'] != "") {
                    $report = \DataExport::getReports($_GET['report_id'],(isset($_GET['instruments']) ? explode(',', $_GET['instruments']) : array()),(isset($_GET['events']) ? explode(',', $_GET['events']) : array()));

                    foreach ($report['fields'] as $columnNumber => $reportField) {
                        if ($metaData[$reportField]['element_type'] == 'descriptive') {
                            unset($report['fields'][$columnNumber]);
                            $report['fields'] = array_values($report['fields']);
                        }
                    }
                    foreach ($report['fields'] as $columnNumber => $reportField) {
                        if (in_array($reportField,$phiFields)) {
                            $phiColumns[] = $columnNumber;
                        }
                        else if ($this->elementHasPHI($metaData[$reportField]['misc'],$phiFields)) {
                            $phiColumns[] = $columnNumber;
                        }
                    }
                }

                if ($this->elementHasPHI($customRecordLabel, $phiFields)) {
                    $javaString = "<script type='text/javascript'>
                        $(document).ready(function() {
                            $('span.crl').each(function() {
                                this.remove();
                            });
                            $('#record_display_name').find('span').remove();
                            $('select[id=\'record\'] > option').each(function() {
                                if (this.value != '') {
                                    this.text = this.value;
                                }
                            });
                            $('#contextMsg span').remove();
                            
                        });";
                    if (strpos($_SERVER[REQUEST_URI],"/DataExport/") !== false && $_GET['report_id'] != "") {
                        $javaString .= "function addObserverIfDesiredNodeAvailable() {
                            var target = $('#report_parent_div')[0];
                            if(!target) {
                                //The node we need does not exist yet.
                                //Wait 500ms and try again
                                window.setTimeout(addObserverIfDesiredNodeAvailable,10);
                                return;
                            }
                            
                            // Create an observer instance
                            var observer = new MutationObserver(function( mutations ) {                              
                                                    mutations.forEach(function( mutation ) {
                                                        var newNodes = mutation.addedNodes;
                                                        if( newNodes !== null ) {
                                                            $(newNodes[0]).find('tbody tr').each(function() {";
                        foreach ($phiColumns as $phiColumn) {
                            $javaString .= "console.log(this.children);if (this.children[$phiColumn].innerHTML != '') {
                                                                        this.children[$phiColumn].innerHTML = '****';
                                                                    }";
                        }
                        $javaString .= "                        });
                                                            $('span.crl').each(function() {
                                                                this.remove();
                                                            });
                                                        }
                                                    });
                            });
                            var config = { 
                                attributes: true, 
                                childList: true, 
                                characterData: true 
                            };
                            observer.observe(target,config);
                        }
                        addObserverIfDesiredNodeAvailable();";
                    }
                    else {
                        echo "Nope no need for observers<br/>";
                    }
                    $javaString .= "</script>";
                    echo $javaString;
                }
            }
        }
    }

    function redcap_data_entry_form_top($project_id, $record, $instrument, $event_id, $group_id = NULL, $repeat_instance = 1) {
        if ($this->userDeIdentified($project_id,USERID)) {
            $currentProject = new \Project($project_id);
            $metaData = $currentProject->metadata;
            $fieldsOnForm = array_keys($currentProject->forms[$instrument]['fields']);
            $removeFields = $this->getProjectSetting('remove-fields');
            $phiFields = array();

            foreach ($metaData as $fieldName => $fieldData) {
                if ($fieldData['field_phi'] == 1) {
                    $phiFields[] = $fieldName;
                }
            }

            $phiData = \Records::getData($project_id,'json',array($record),$phiFields);

            $javaString = "<script type='text/javascript'>$(document).ready(function() {";
            foreach ($fieldsOnForm as $field) {
                if (in_array($field,$phiFields)) {
                    $javaString .= $this->hideFormField($metaData[$field], $removeFields);
                }

                $labelHasPHI = $this->elementHasPHI($metaData[$field]['element_label'],$phiFields);
                $pipingHasPHI = $this->elementHasPHI($metaData[$field]['misc'],$phiFields);

                if ($labelHasPHI) {
                    $sanitizedLabel = str_replace("\r\n","",nl2br(decode_filter_tags($metaData[$field]['element_label'])));
                    foreach ($phiFields as $phiField) {
                        if (strpos($sanitizedLabel,"[".$phiField."]") !== false) {
                            $sanitizedLabel = str_replace("[".$phiField."]","****",$sanitizedLabel);
                        }
                    }
                    $embeddedFieldReplacement = \Piping::replaceVariablesInLabel($sanitizedLabel, $record, $event_id);
                    $javaString .= $this->changeFieldLabel($field,$embeddedFieldReplacement);
                }
                if ($pipingHasPHI) {
                    $javaString .= $this->hideFormField($metaData[$field], $removeFields);
                }

            }

            $javaString .= "});</script>";
            echo $javaString;
        }
    }

    private function hideFormField($fieldMeta,$removeFields) {
        $returnString = "";
        $fieldType = $fieldMeta['element_type'];
        switch ($fieldType) {
            case 'text':
                $returnString .= "$('input[name=\"".$fieldMeta['field_name']."\"]').attr('type','password').prop('disabled',true);";
                break;
        }
        return $returnString;
    }

    private function changeFieldLabel($fieldName,$label) {
        $returnString = "";
        $returnString = "$('tr[id=\'$fieldName-tr\'] td').first().html('$label');";
        return $returnString;
    }

    private function hasCustomRecordLabel($project_id) {
        if(empty($project_id) || !is_numeric($project_id)){
            return null;
        }

        $result = $this->query("select custom_record_label from redcap_projects where project_id = " . $this->getProjectId());
        $row = $result->fetch_assoc();

        return $row['custom_record_label'];
    }

    private function elementHasPHI($customString,$phiFields) {
        $matchRegEx = $this->pregMatchFields($customString);
        $stringsToReplace = $matchRegEx[0];
        $fieldNamesReplace = $matchRegEx[1];

        foreach ($fieldNamesReplace as $index => $fieldName) {
            if (in_array($fieldName,$phiFields)) {
                return true;
            }
        }
        return false;
    }

    private function pregMatchFields($checkString) {
        preg_match_all("/\[(.*?)\]/",$checkString,$matchRegEx);
        return $matchRegEx;
    }

    private function userDeIdentified($project_id,$user) {
        if (\UserRights::isImpersonatingUser()) {
            $user = \UserRights::getUsernameImpersonating();
        }

        $userRights = \UserRights::getPrivileges($project_id, $user);
        $userRights = $userRights[PROJECT_ID][strtolower($user)];

        if ($userRights['data_export_tool'] != "1") {
            return true;
        }
        return false;
    }
}