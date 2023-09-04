<?php namespace DE\RUB\QRCodeExternalModule;

use REDCap;
use Records;
use Files;
use QRcode;

class QRCodeExternalModule extends \ExternalModules\AbstractExternalModule {

    private $at = "@QRCODE";

    #region Hooks

    function redcap_save_record ($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1) {
        // Only when an instrument is saved and the @QRCODE action tag is present
        if ($instrument) {
            $tags = $this->getQRCodes($project_id, $instrument);
            if (count($tags)) {
                $this->saveQRCode($project_id, $record, $instrument, $event_id, $repeat_instance, $tags);
            }
        }
    }

    function redcap_survey_page_top($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1) {
        $tags = $this->getQRCodes($project_id, $instrument);
        if (count($tags)) {
            $this->injectJS($tags);
        }
    }

    function redcap_data_entry_form_top($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance = 1) {
        $tags = $this->getQRCodes($project_id, $instrument);
        if (count($tags)) {
            $this->injectJS($tags);
        }
    }

    #endregion

    #region Browser Logic

    /**
     * Injects JavaScript into data entry forms and survey pages that removes the File Upload links for fields with the action tag
     * @param Array $tags 
     * @return void 
     */
    private function injectJS($tags) {
        $js = [];
        foreach ($tags as $tag) {
            if ($tag["type"] == "file") {
                $js[] = "$('#{$tag["field"]}-linknew a.fileuploadlink').remove()";
                $js[] = "$('#{$tag["field"]}-linknew span').not('.edoc-link').remove()";
            }
        }
		
        if (count($js)) {
            print "<script>$(function() { " . join("; ", $js) . " });</script>";
        }
    }

    #endregion

    #region Server Logic 

    /**
     * Parses @QRCODE action tags
     * @param mixed $pid 
     * @param mixed $instrument 
     * @return array [[ field, type, source ]]
     */
    private function getQRCodes($pid, $instrument) {
        global $Proj;
        $tags = array();
        if ($Proj->project_id == $pid && array_key_exists($instrument, $Proj->forms)) {
            // Check field metadata for action tag
            // https://regex101.com/r/0a5iYM/2
            $re = "/{$this->at}\s{0,}=\s{0,}(?<q>[\"'])(?<f>[a-z0-9_]+)(?P=q)/m";
            foreach ($Proj->forms[$instrument]["fields"] as $fieldName => $_) {
                $meta = $Proj->metadata[$fieldName];
                // Only text (no validation) and file uploads allowed!
                if (($meta["element_type"] == "text" || $meta["element_type"] == "file") && $meta["element_validation_type"] == null) {
                    $misc = $Proj->metadata[$fieldName]["misc"];
                    preg_match_all($re, $misc, $matches, PREG_SET_ORDER, 0);
                    foreach ($matches as $match) {
                        if (array_key_exists($match["f"], $Proj->forms[$instrument]["fields"])) {
                            $tags[] = array(
                                "field" => preg_replace("/[^a-z0-9_]/", "", $fieldName),
                                "type" => $meta["element_type"],
                                "source" => $match["f"],
                            );
                        }
                    }
                }
            }
        }
        return $tags;
    }

    /**
     * Saves QR codes to fields tagged with the action tag
     * @param int $project_id 
     * @param string $record 
     * @param string $instrument 
     * @param string|int $event_id 
     * @param string|int $repeat_instance 
     * @param Array $tags 
     * @return void 
     */
    private function saveQRCode($project_id, $record, $instrument, $event_id, $repeat_instance, $tags) {
        global $Proj;
        // Load QR library - note: Changed path in REDCap 13.9.3+
        if (file_exists(APP_PATH_LIBRARIES . "phpqrcode/qrlib.php")) {
            require_once APP_PATH_LIBRARIES . "phpqrcode/qrlib.php";
        }
        else if (file_exists(APP_PATH_LIBRARIES . "phpqrcode/lib/full/qrlib.php")) {
            require_once APP_PATH_LIBRARIES . "phpqrcode/lib/full/qrlib.php";
        }
        else {
            throw new \Exception("Failed to load REDCap's QR library.");
        }
        require_once APP_PATH_CLASSES . "Records.php";
        require_once APP_PATH_CLASSES . "Files.php";
        $source_fields = array_unique(array_map(function($tag) { return $tag["source"]; }, $tags));
        $data = REDCap::getData($project_id, "array", $record, $source_fields, $event_id); 
        $repeating_event = $Proj->isRepeatingEvent($event_id);
        $repeating_form = $Proj->isRepeatingForm($event_id, $instrument);
        foreach ($tags as $tag) {
            $text = $data[$record][$event_id][$tag["source"]];
            if ($repeating_event) {
                $text = $data[$record]["repeat_instances"][$event_id][""][$repeat_instance][$tag["source"]]; 
            }
            else if ($repeating_form) {
                $text = $data[$record]["repeat_instances"][$event_id][$instrument][$repeat_instance][$tag["source"]];
            }
            // Create QRCode (PNG) in a temporary file
            $tempFile = APP_PATH_TEMP . "temp_QR_" . sha1(microtime() . rand(1,10000000)) . "_" . time() . ".png";
            QRcode::png($text, $tempFile, 0, 3, 2);
            $png_or_edoc = "";
            if ($tag["type"] == "text") {
                $png_or_edoc = base64_encode(file_get_contents($tempFile));
            }
            else if ($tag["type"] == "file") {
                // Determine if there is a need to overwrite
                // Is there a previous uploaded version?
                $prev_doc_id = null;
                $data = REDCap::getData($project_id, "array", $record, $tag["field"], $event_id);
                if ($repeating_event) {
                    $prev_doc_id = $data[$record]["repeat_instances"][$event_id][""][$repeat_instance][$tag["field"]]; 
                }
                else if ($repeating_form) {
                    $prev_doc_id = $data[$record]["repeat_instances"][$event_id][$instrument][$repeat_instance][$tag["field"]];
                }
                else {
                    $prev_doc_id = $data[$record][$event_id][$tag["field"]];
                }
                $prevHash = "prev";
                $newHash = sha1_file($tempFile);
                if ($prev_doc_id) {
                    // Determine hash of previous file
                    $prevFile = Files::copyEdocToTemp($prev_doc_id, true, true);
                    $prevHash = sha1_file($prevFile);
                    unlink($prevFile);
                }
                if ($prevHash == $newHash) {
                    // The files are identical, skip saving by setting $png_or_edoc to null
                    $png_or_edoc = null;
                }
                else {
                    // A new qr code - upload to edocs and delete the previous one
                    $png_or_edoc = Files::uploadFile(array(
                        "name" => "QR_{$tag["source"]}.png",
                        "tmp_name" => $tempFile,
                        "size" => filesize($tempFile),
                    ), $project_id);
                    if ($prev_doc_id) {
                        Files::deleteFileByDocId($prev_doc_id, $project_id);
                    }
                }
            }
            if (!empty($png_or_edoc)) {
                $saveData = [];
                if ($repeating_event) {
                    $saveData[$record]["repeat_instances"][$event_id][""][$repeat_instance][$tag["field"]] = $png_or_edoc;
                }
                else if ($repeating_form) {
                    $saveData[$record]["repeat_instances"][$event_id][$instrument][$repeat_instance][$tag["field"]] = $png_or_edoc;
                }
                else {
                    $saveData[$record][$event_id][$tag["field"]] = $png_or_edoc;
                }
                Records::saveData(array(
                    "project_id" => $project_id,
                    "dataFormat" => "array",
                    "data" => $saveData,
                    "skipFileUploadFields" => false,
                ));
            }
            // Cleanup (will already have happened in case the destination is a file upload field, but doesn't hurt)
            unlink($tempFile);
        }
    }

    #endregion
}
