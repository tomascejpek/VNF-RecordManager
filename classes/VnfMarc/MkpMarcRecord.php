<?php
/**
 * MarcRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2013
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */

require_once 'VnfMarcRecord.php';

/**
 * MarcRecord Class - local customization for VNF marc record
 *
 * This is a class for processing MARC records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Michal Merta <merta.m@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/moravianlibrary/RecordManager
 */
class MkpMarcRecord extends VnfMarcRecord
{

    /**
     * Constructor
     *
     * @param string $data   Metadata
     * @param string $oaiID  Record ID received from OAI-PMH (or empty string for file import)
     * @param string $source Source ID
     */
    public function __construct($data, $oaiID, $source)
    {
        parent::__construct($data, $oaiID, $source);
    }
  
    public function getFormat() {
        $field = $this->getField('910');
//         if ($field) {
//             $subfield = $this->getSubfield($field, 'b');
//             if ($subfield) {
//                 if (stripos($subfield,'CD') !== false) {
//                     $format = 'mkp_CD';
//                 } elseif (stripos($subfield,'KZ') !== false) {
//                     $format = 'mkp_KZ';
//                 } elseif (stripos($subfield,'SV') !== false) {
//                     $format = 'mkp_SV';
//                 }
//             }
//         }
        $subfields = $this->getFieldsAllSubfields('653');
        if ($subfields && is_array($subfields) && count($subfields) > 0) {
            $formatSub = preg_grep("/nosi/", $subfields[0]);
            if (count($formatSub) > 0) {
                $format = 'mkp_' . trim(substr(current($formatSub), 7)); 
            }
        }

        $formats = isset($format) ? $this->unifyFormats(array($format)) : array();
        $formats[] = 'vnf_album';
        return $formats;
    }
    
}
