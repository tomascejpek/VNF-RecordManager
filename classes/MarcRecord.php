<?php
/**
 * MarcRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala 2011-2012
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
 */

require_once 'BaseRecord.php';
require_once 'MetadataUtils.php';

/**
 * MarcRecord Class
 *
 * This is a class for processing MARC records.
 *
 */
class MarcRecord extends BaseRecord
{
    const SUBFIELD_INDICATOR = "\x1F";
    const END_OF_FIELD = "\x1E";
    const END_OF_RECORD = "\x1D";
    const LEADER_LEN = 24;

    protected $_fields;
    protected $_idPrefix = '';

    public function __construct($data, $oaiID)
    {
        $firstChar = substr($data, 0, 1);
        if ($firstChar === '{') {
            $this->_fields = json_decode($data, true);
        } elseif ($firstChar === '<') {
            $this->_parseXML($data);
        } else {
            $this->_parseISO2709($data);
        }
        if (isset($this->_fields['000']) && is_array($this->_fields['000'])) {
            $this->_fields['000'] = $this->_fields['000'][0];
        }
    }

    public function serialize()
    {
        return json_encode($this->_fields);
    }

    public function toXML()
    {
        $xml = simplexml_load_string("<?xml version=\"1.0\" encoding=\"utf-8\"?>\n\n<collection><record></record></collection>");
        $record = $xml->record[0];

        if (isset($this->_fields['000'])) {
        	// Voyager is often missing the last '0' of the leader...
        	$leader = str_pad(substr($this->_fields['000'], 0, 24), 24);
            $record->addChild('leader', $leader);
        }
        	
        foreach ($this->_fields as $tag => $fields) {
            if ($tag == '000') {
                continue;
            }
            foreach ($fields as $data) {
                if (is_numeric($tag) && $tag < 10) {
                    $field = $record->addChild('controlfield', htmlspecialchars($data, ENT_NOQUOTES));
                    $field->addAttribute('tag', $tag);
                } else {
                    $field = $record->addChild('datafield');
                    $field->addAttribute('tag', $tag);
                    $field->addAttribute('ind1', substr($data, 0, 1));
                    $field->addAttribute('ind2', substr($data, 1, 1));
                    $subfields = explode(MARCRecord::SUBFIELD_INDICATOR, substr($data, 2));
                    foreach ($subfields as $subfieldData) {
                        if ($subfieldData == '') {
                            continue;
                        }
                        $subfield = $field->addChild('subfield', htmlspecialchars(substr($subfieldData, 1), ENT_NOQUOTES));
                        $subfield->addAttribute('code', substr($subfieldData, 0, 1));
                    }
                }
            }
        }

        return $record->asXML();
    }

    public function toSolrArray()
    {
        $data = parent::toSolrArray();
        	
        // building
        $data['building'] = $this->_getFieldsSubfields('852bc');
        	
        // long_lat
        $field = $this->_getField('034');
        if ($field) {
            $west = MetadataUtils::coordinateToDecimal($this->_getSubfield($field, 'd'));
            $east = MetadataUtils::coordinateToDecimal($this->_getSubfield($field, 'e'));
            $north = MetadataUtils::coordinateToDecimal($this->_getSubfield($field, 'f'));
            $south = MetadataUtils::coordinateToDecimal($this->_getSubfield($field, 'g'));

            if (!is_nan($west) && !is_nan($north)) {
                if (!is_nan($east)) {
                    $west = ($west + $east) / 2;
                }
                if (!is_nan($south)) {
                    $north = ($north + $south) / 2;
                }
                $data['long_lat'] = $west . ',' . $north;
            }
        }

        // lccn
        $data['lccn'] = $this->_getFieldSubfields('010', 'a');
        $data['ctrlnum'] = $this->_getFieldsSubfields('035', 'a');
        $data['fullrecord'] = $this->_toISO2709();
        if (!$data['fullrecord']) {
            // In case the record exceeds 99999 bytes...
            $data['fullrecord'] = $this->toXML();
        }
        	
        // allfields
        $allFields = '';
        foreach ($this->_fields as $tag => $fields) {
            if (($tag >= 100 && $tag < 900) || $tag == 979) {
                foreach ($fields as $field) {
                    if ($allFields) {
                        $allFields .= ' ';
                    }
                    $allFields .= $this->_getAllSubfields($field);
                }
            }
        }
        //echo "allFields: $allFields\n";
        $data['allfields'] = $allFields;
        	
        // language
        $languages = array(substr($this->_getField('008'), 35, 3));
        $languages += $this->_getFieldsSubfields('041a:041d:041h:041j');
        foreach ($languages as $language) {
            if (preg_match('/^\w{3}$/', $language))
            $data['language'][] = $language;
        }
        	
        $data['format'] = $this->getFormat();
        $data['author'] = $this->_getFieldSubfields('100abcd', true);
        $data['author_fuller'] = $this->_getFieldSubfields('100q');
        $data['author-letter'] = $this->_getFieldSubfields('100a', true);

        $data['author2'] = $this->_getFieldsSubfields('110ab:111ab:700abcd:710ab:711ab:979c', false, true); // 979c = component part author
        $data['author2-role'] = $this->_getFieldsSubfields('700e:710e');
        $data['author_additional'] = $this->_getFieldsSubfields('505r');
        	
        $data['title'] = $data['title_auth'] = $this->getTitle();
        $data['title_sub'] = $this->_getFieldSubfields('245b', true);
        $data['title_short'] = $this->_getFieldSubfields('245a', true);
        $data['title_full'] = $this->_getFieldSubfields('245');
        $data['title_alt'] = $this->_getFieldsSubfields('130adfgklnpst:240a:246a:730adfgklnpst:740a:979b', false, true); // 979c = component part title
        $data['title_old'] = $this->_getFieldsSubfields('780ast');
        $data['title_new'] = $this->_getFieldsSubfields('785ast');
        $data['title_sort'] = $this->getTitle(true);

        $data['series'] = $this->_getFieldsSubfields('440ap:800abcdfpqt:830ap');
        	
        $data['publisher'] = $this->_getFieldsSubfields('260b');
        $data['publishDate'] = $data['publishDateSort'] = $this->getPublicationYear();
        $data['physical'] = $this->_getFieldsSubfields('300abcefg:530abcd');
        $data['dateSpan'] = $this->_getFieldsSubfields('362a');
        $data['edition'] = $this->_getFieldSubfields('250a');
        $data['contents'] = $this->_getFieldsSubfields('505a:505t');
        	
        $data['isbn'] = $this->getISBNs();
        $data['issn'] = $this->_getFieldsSubfields('022a:440x:490x:730x:776x:780x:785x');

        // TODO: Does this make sense for us at all?
        $data['callnumber'] = strtoupper(str_replace(' ', '', $this->_getFirstFieldSubfields('080ab:084ab:050ab')));
        $data['callnumber-a'] = $this->_getFirstFieldSubfields('080a:084a:050a');
        $data['callnumber-first-code'] = substr($this->_getFirstFieldSubfields('080a:084a:050a'), 0, 1);

        $data['topic'] = $this->_getFieldsSubfields('600:610:611:630:650');
        $data['genre'] = $this->_getFieldsSubfields('655');
        $data['geographic'] = $this->_getFieldsSubfields('651');
        $data['era'] = $this->_getFieldsSubfields('648');

        $data['topic_facet'] = $this->_getFieldsSubfields('600x:610x:611x:630x:648x:650a:650x:651x:655x');
        $data['genre_facet'] = $this->_getFieldsSubfields('600v:610v:611v:630v:648v:650v:651v:655a:655v');
        $data['geographic_facet'] = $this->_getFieldsSubfields('600z:610z:611z:630z:648z:650z:651a:651z:655z');
        $data['era_facet'] = $this->_getFieldsSubfields('600d:610y:611y:630y:648a:648y:650y:651y:655y');

        $data['url'] = $this->_getFieldsSubfields('856u');

        $data['illustrated'] = $this->_getIllustrated();

        // TODO: Do we need dewey fields or OCLC numbers?
        	
        return $data;
    }

    public function mergeComponentParts($componentParts)
    {
        $count = 0;
        $this->_fields['979'] = array();
        foreach ($componentParts as $componentPart) {
            $marc = new MARCRecord($componentPart['normalized_data'] ? $componentPart['normalized_data'] : $componentPart['original_data'], '');
            $title = $marc->_getFieldSubfields('245ab');
            $uniTitle = $marc->_getFieldSubfields('240a');
            if ($uniTitle) {
                $title .= " [$uniTitle]";
            }
            $author = $marc->_getFieldSubfields('100a');
            $id = $this->_idPrefix . $marc->getID();

            $comp = MARCRecord::SUBFIELD_INDICATOR . "a$id";
            if ($title) {
                $comp .= MARCRecord::SUBFIELD_INDICATOR . "b$title";
            }
            if ($author) {
                $comp .= MARCRecord::SUBFIELD_INDICATOR . "c$author";
            }
            $this->_fields['979'][] = $comp;
            ++$count;
        }
        return $count;
    }

    public function getID()
    {
        //echo "ID: *" . $this->_marcRecord->getField('001')->getData() ."*\n";
        return $this->_getField('001');
    }

    public function setIDPrefix($prefix)
    {
        $this->_idPrefix = $prefix;
        //echo "ID: *" . $this->_marcRecord->getField('001')->getData() ."*\n";
        $id = $this->_getField('001');
        $id = "$prefix$id";
        $this->_setField('001', array($id));

        if (isset($this->_fields['773'])) {
            foreach	($this->_fields['773'] as $key => $field) {
                $field = preg_replace('/' . MARCRecord::SUBFIELD_INDICATOR . 'w([^' . MARCRecord::SUBFIELD_INDICATOR . ']+)/', MARCRecord::SUBFIELD_INDICATOR . "w$prefix\\1", $field);
                $this->_fields['773'][$key] = $field;
            }
        }
    }

    public function getHostRecordID()
    {
        //echo "ID: *" . $this->_marcRecord->getField('001')->getData() ."*\n";
        $field = $this->_getField('773');
        if (!$field)
        return '';
        return $this->_getSubfield($field, 'w');
    }

    public function getTitle($forFiling = false)
    {
        $field = $this->_getField('245');
        if (!$field) {
            $field = $this->_getField('240');
        }
        if ($field)
        {
            $title = $this->_getSubfield($field, 'a');
            if ($forFiling)
            {
                $nonfiling = $this->_getIndicator($field, 2);
                if ($nonfiling > 0)
                $title = substr($title, $nonfiling);
            }
            $sub_b = $this->_getSubfield($field, 'b');
            if ($sub_b)
            $title .= " $sub_b";
            $sub_n = $this->_getSubfield($field, 'n');
            if ($sub_n)
            $title .= " $sub_n";
            $sub_p = $this->_getSubfield($field, 'p');
            if ($sub_p)
            $title .= " $sub_p";
            return MetadataUtils::stripTrailingPunctuation($title);
        }
        return '';
    }

    public function getMainAuthor()
    {
        $f100 = $this->_getField('100');
        if ($f100)
        {
            $author = $this->_getSubfield($f100, 'a');
            $order = $this->_getIndicator($f100, 1);
            if ($order == 0 && strpos($author, ',') === false) {
                $p = strrpos($author, ' ');
                if ($p > 0) {
                    $author = substr($author, $p + 1) . ', ' . substr($author, 0, $p);
                }
            }
            return MetadataUtils::stripTrailingPunctuation($author);
        }
        /* Not good idea?
         $f110 = $this->_getField('110');
        if ($f110)
        {
        $author = $this->_getSubfield($f110, 'a');
        return $author;
        }
        */
        return '';
    }

    // For debug display only
    public function getFullTitle()
    {
        $f245 = $this->_getField('245');
        return $f245;
    }

    // Array of ISBN 13 without dashes
    public function getISBNs()
    {
        $arr = array();
        $fields = $this->_getFields('020');
        foreach ($fields as $field)
        {
            $isbn = $this->_getSubfield($field, 'a');
            $isbn = str_replace('-', '', $isbn);
            if (!preg_match('{([0-9]{9,12}[0-9xX])}', $isbn, $matches)) {
                continue;
            };
            $isbn = $matches[1];
            if (strlen($isbn) == 10) {
                $isbn = MetadataUtils::isbn10to13($isbn);
            }
            if ($isbn) {
                $arr[] = $isbn;
            }
        }

        return $arr;
    }

    public function getSeriesISSN()
    {
        $field = $this->_getField('490');
        if (!$field)
        return '';
        return $this->_getSubfield($field, 'x');
    }

    public function getSeriesNumbering()
    {
        $field = $this->_getField('490');
        if (!$field)
        return '';
        return $this->_getSubfield($field, 'v');
    }

    // Format is from a predefined list
    public function getFormat()
    {
        $field008 = $this->_getField('008');
        // check the 007 - this is a repeating field
        $fields = $this->_getFields('007');
        $formatCode = '';
        $online = false;
        foreach ($fields as $field)
        {
            $contents = $field;
            $formatCode = strtoupper(substr($contents, 0, 1));
            $formatCode2 = strtoupper(substr($contents, 1, 1));
            switch ($formatCode) {
                case 'A':
                    switch($formatCode2) {
                        case 'D':
                            return 'Atlas';
                            break;
                        default:
                            return 'Map';
                        break;
                    }
                    break;
                case 'C':
                    switch($formatCode2) {
                        case 'A':
                            return 'TapeCartridge';
                            break;
                        case 'B':
                            return 'ChipCartridge';
                            break;
                        case 'C':
                            return 'DiscCartridge';
                            break;
                        case 'F':
                            return 'TapeCassette';
                            break;
                        case 'H':
                            return 'TapeReel';
                            break;
                        case 'J':
                            return 'FloppyDisk';
                            break;
                        case 'M':
                        case 'O':
                            return 'CDROM';
                            break;
                        case 'R':
                            // Do not return - this will cause anything with an
                            // 856 field to be labeled as "Electronic"
                            $online = true;
                            break;
                        default:
                            return 'Software';
                        break;
                    }
                    break;
                case 'D':
                    return 'Globe';
                    break;
                case 'F':
                    return 'Braille';
                    break;
                case 'G':
                    switch($formatCode2) {
                        case 'C':
                        case 'D':
                            return 'Filmstrip';
                            break;
                        case 'T':
                            return 'Transparency';
                            break;
                        default:
                            return 'Slide';
                        break;
                    }
                    break;
                case 'H':
                    return 'Microfilm';
                    break;
                case 'K':
                    switch($formatCode2) {
                        case 'C':
                            return 'Collage';
                            break;
                        case 'D':
                            return 'Drawing';
                            break;
                        case 'E':
                            return 'Painting';
                            break;
                        case 'F':
                            return 'Print';
                            break;
                        case 'G':
                            return 'Photonegative';
                            break;
                        case 'J':
                            return 'Print';
                            break;
                        case 'L':
                            return 'Drawing';
                            break;
                        case 'O':
                            return 'FlashCard';
                            break;
                        case 'N':
                            return 'Chart';
                            break;
                        default:
                            return 'Photo';
                        break;
                    }
                    break;
                case 'M':
                    switch($formatCode2) {
                        case 'F':
                            return 'VideoCassette';
                            break;
                        case 'R':
                            return 'Filmstrip';
                            break;
                        default:
                            return 'MotionPicture';
                        break;
                    }
                    break;
                case 'O':
                    return 'Kit';
                    break;
                case 'Q':
                    return 'MusicalScore';
                    break;
                case 'R':
                    return 'SensorImage';
                    break;
                case 'S':
                    switch($formatCode2) {
                        case 'D':
                            return 'SoundDisc';
                            break;
                        case 'S':
                            return 'SoundCassette';
                            break;
                        default:
                            return 'SoundRecording';
                        break;
                    }
                    break;
                case 'V':
                    $videoFormat = strtoupper(substr($contents, 4, 1));
                    switch($videoFormat) {
                        case 'S':
                            return 'BluRay';
                        case 'V':
                            return 'DVD';
                    }

                    switch($formatCode2) {
                        case 'C':
                            return 'VideoCartridge';
                            break;
                        case 'D':
                            return 'VideoDisc';
                            break;
                        case 'F':
                            return 'VideoCassette';
                            break;
                        case 'R':
                            return 'VideoReel';
                            break;
                        default:
                            return 'Video';
                        break;
                    }
                    break;
            }
        }


        // check the Leader at position 6
        $leader = $this->_getField('000');
        $leaderBit = substr($leader, 6, 1);
        switch (strtoupper($leaderBit)) {
            case 'C':
            case 'D':
                return 'MusicalScore';
                break;
            case 'E':
            case 'F':
                return 'Map';
                break;
            case 'G':
                return 'Slide';
                break;
            case 'I':
                return 'SoundRecording';
                break;
            case 'J':
                return 'MusicRecording';
                break;
            case 'K':
                return 'Photo';
                break;
            case 'M':
                return 'Electronic';
                break;
            case 'O':
            case 'P':
                return 'Kit';
                break;
            case 'R':
                return 'PhysicalObject';
                break;
            case 'T':
                return 'Manuscript';
                break;
        }

        // check the Leader at position 7
        $leaderBit = substr($leader, 7, 1);
        switch (strtoupper($leaderBit)) {
            // Monograph
            case 'M':
                if ($formatCode == 'C') {
                    return 'eBook';
                } else {
                    return 'Book';
                }
                break;
                // Serial
            case 'S':
                // Look in 008 to determine what type of Continuing Resource
                $formatCode = strtoupper(substr($field008, 21, 1));
                switch ($formatCode) {
                    case 'N':
                        return 'Newspaper';
                        break;
                    case 'P':
                        return 'Journal';
                        break;
                    default:
                        return 'Serial';
                    break;
                }
                break;
                // Component part in monograph
            case 'A': return $formatCode == 'C' ? 'eBookPart' : 'BookPart';
            // Component part in serial
            case 'B': return $formatCode == 'C' ? 'eJournalArticle' : 'JournalArticle';
            // Collection
            case 'C': return 'Collection';
            // Component part in collection (sub unit)
            case 'D': return 'Subunit';
            // Integrating resource
            case 'I': return 'ContinuouslyUpdatedResource';
        }
        return '';
    }

    // Four digits
    public function getPublicationYear()
    {
        $field = $this->_getField('260');
        if ($field) {
            $year = $this->_getSubfield($field, 'c');
            $matches = array();
            if ($year && preg_match('/(\d{4})/', $year, $matches)) {
                return $matches[1];
            }
        }
        $field008 = $this->_getField('008');
        if (!$field008) {
            return '';
        }
        $year = substr($field008, 7, 4);
        $matches = array();
        if ($year && preg_match('/(\d{4})/', $year, $matches)) {
            return $matches[1];
        }
        return '';
    }

    public function getPageCount()
    {
        $field = $this->_getField('300');
        if ($field) {
            $extent = $this->_getSubfield($field, 'a');
            if ($extent && preg_match('/(\d+)/', $extent, $matches)) {
                return $matches[1];
            }
        }
        return '';
    }

    public function addDedupKeyToMetadata($dedupKey)
    {
        if ($dedupKey) {
            $this->_fields['995'] = array('  ' . MARCRecord::SUBFIELD_INDICATOR . 'a' . $dedupKey);
        } else {
            $this->_fields['995'] = array();
        }
    }

    protected function _parseXML($marc)
    {
        $xmlHead = '<?xml version';
        if (strcasecmp(substr($marc, 0, strlen($xmlHead)), $xmlHead) === 0) {
            $decl = substr($marc, 0, strpos($marc, '?>'));
            if (strstr($decl, 'encoding') === false) {
                $marc = $decl .  ' encoding="utf-8"' . substr($marc, strlen($decl));
            }
        }
        else {
            $marc = '<?xml version="1.0" encoding="utf-8"?>' . "\n\n$marc";
        }
        $xml = simplexml_load_string($marc);

        $this->_fields['000'] = isset($xml->leader) ? $xml->leader[0] : '';

        foreach ($xml->controlfield as $field) {
            $this->_fields[(string)$field['tag']][] = (string)$field;
        }

        foreach ($xml->datafield as $field) {
            $fieldData = (string)$field['ind1'] . (string)$field['ind2'];
            foreach ($field->subfield as $subfield) {
                $fieldData .= MARCRecord::SUBFIELD_INDICATOR . (string)$subfield['code'] . (string)$subfield;
            }
            $this->_fields[(string)$field['tag']][] = $fieldData;
        }
    }

    protected function _parseISO2709($marc)
    {
        $this->_fields['000'] = substr($marc, 0, 24);
        $dataStart = 0 + substr($marc, 12, 5);
        $dirLen = $dataStart - MARCRecord::LEADER_LEN - 1;

        $offset = 0;
        while ($offset < $dirLen) {
            $tag = substr($marc, MARCRecord::LEADER_LEN + $offset, 3);
            $len = substr($marc, MARCRecord::LEADER_LEN + $offset + 3, 4);
            $dataOffset = substr($marc, MARCRecord::LEADER_LEN + $offset + 7, 5);

            $tagData = substr($marc, $dataStart + $dataOffset, $len);

            if (substr($tagData, -1, 1) == MARCRecord::END_OF_FIELD) {
                $tagData = substr($tagData, 0, -1);
                $len--;
            } else {
                die("Invalid MARC record (end of field not found): $marc");
            }

            $this->_fields[$tag][] = $tagData;
            $offset += 12;
        }
    }

    protected function _toISO2709()
    {
        $leader = str_pad(substr($this->_fields['000'], 0, 24), 24);

        $directory = '';
        $data = '';
        $datapos = 0;
        foreach ($this->_fields as $tag => $fields) {
            if ($tag == '000') {
                continue;
            }
            if (strlen($tag) != 3) {
                error_log("Invalid field tag: '$tag', id " . $this->_getField('001'));
                continue;
            }
            foreach ($fields as $field) {
                $field .= MARCRecord::END_OF_FIELD;
                $len = strlen($field);
                if ($len > 9999) {
                    error_log("Field too long ($len): '$field', id " . $this->_getField('001'));
                    break;
                }
                if ($datapos > 99999) {
                    error_log("Record too long ($datapos), id " . $this->_getField('001'));
                    return '';
                }
                $directory .= $tag . str_pad($len, 4, '0', STR_PAD_LEFT) . str_pad($datapos, 5, '0', STR_PAD_LEFT);
                $datapos += $len;
                $data .= $field;
            }
        }
        $directory .= MARCRecord::END_OF_FIELD;
        $data .= MARCRecord::END_OF_RECORD;
        $dataStart = strlen($leader) + strlen($directory);
        $recordLen = $dataStart + strlen($data);
        if ($recordLen > 99999) {
            error_log("Record too long ($recordLen), id " . $this->_getField('001'));
            return '';
        }

        $leader = str_pad($recordLen, 5, '0', STR_PAD_LEFT) . substr($leader, 5, 7) . str_pad($dataStart, 5, '0', STR_PAD_LEFT) . substr($leader, 17);
        return $leader . $directory . $data;
    }

    protected function _getIllustrated()
    {
        $leader = $this->_getField('000');
        if (substr($leader, 6, 1) == 'a') {
            $illustratedCodes = 'abcdefghijklmop';

            // 008
            $field008 = $this->_getField('008');
            for ($pos = 18; $pos <= 21; $pos++) {
                if (strpos($illustratedCodes, substr($field008, $pos, 1)) !== false) {
                    return 'Illustrated';
                }
            }

            // 006
            foreach ($this->_getFields('006') as $field006) {
                for ($pos = 1; $pos <= 4; $pos++) {
                    if (strpos($illustratedCodes, substr($field006, $pos, 1)) !== false) {
                        return 'Illustrated';
                    }
                }
            }
        }

        // Now check for interesting strings in 300 subfield b:
        $illustrationStrings = array('ill.', 'illus.', 'kuv.');
        foreach ($this->_getFields('300') as $field300) {
            $sub = strtolower($this->_getSubfield($field300, 'b'));
            foreach ($illustrationStrings as $illStr) {
                if (strpos($sub, $illStr) !== false) {
                    return 'Illustrated';
                }
            }
        }
        return 'Not Illustrated';
    }

    protected function _getField($field)
    {
        if (isset($this->_fields[$field])) {
            if (is_array($this->_fields[$field])) {
                return $this->_fields[$field][0];
            } else {
                return $this->_fields[$field];
            }
        }
        return '';
    }

    protected function _getFields($field)
    {
        if (isset($this->_fields[$field])) {
            return $this->_fields[$field];
        }
        return array();
    }

    protected function _getIndicator($field, $indicator)
    {
        switch ($indicator) {
            case 1: return substr($field, 0, 1);
            case 2: return substr($field, 1, 1);
            default: die("Invalid indicator '$indicator' requested\n");
        }
    }

    protected function _getSubfield($field, $subfield)
    {
        $p = strpos($field, MARCRecord::SUBFIELD_INDICATOR . $subfield);
        if ($p === false) {
            return '';
        }
        $data = substr($field, $p + 2);
        $p = strpos($data, MARCRecord::SUBFIELD_INDICATOR);
        if ($p !== false) {
            $data = substr($data, 0, $p);
        }
        return $data;
    }

    protected function _getSubfields($field, $subfields)
    {
        $data = '';
        foreach (str_split($subfields) as $code) {
            $subfield = $this->_getSubfield($field, $code);
            if ($subfield) {
                if ($data) {
                    $data .= ' ';
                }
                $data .= $subfield;
            }
        }
        return $data;
    }

    // Space-separated subfields
    protected function _getFieldSubfields($fieldspec, $stripTrailingPunctuation = false)
    {
        $tag = substr($fieldspec, 0, 3);
        $subfields = substr($fieldspec, 3);
        if ($subfields) {
            $data = $this->_getSubfields($this->_getField($tag), $subfields);
        } else {
            $data = $this->_getAllSubfields($this->_getField($tag));
        }
        if ($stripTrailingPunctuation) {
            $data = MetadataUtils::stripTrailingPunctuation($data);
        }
        return $data;
    }

    // Array of fields with space-separated subfields
    protected function _getFieldsSubfields($fieldspecs, $firstOnly = false, $stripTrailingPunctuation = false)
    {
        $data = array();
        foreach (explode(':', $fieldspecs) as $fieldspec) {
            $tag = substr($fieldspec, 0, 3);
            $subfields = substr($fieldspec, 3);
            foreach ($this->_getFields($tag) as $field) {
                $fieldContents = $this->_getSubfields($field, $subfields);
                if ($fieldContents) {
                    if ($stripTrailingPunctuation) {
                        $fieldContents = MetadataUtils::stripTrailingPunctuation($fieldContents);
                    }
                    $data[] = $fieldContents;
                    if ($firstOnly) {
                        break 2;
                    }
                }
            }
        }
        return $data;
    }

    // Array of fields with space-separated subfields
    protected function _getFieldsAllSubfields($tag)
    {
        $data = array();
        foreach ($this->_getFields($tag) as $field) {
            $fieldContents = $this->_getAllSubfields($field);
            if ($fieldContents) {
                $data[] = $fieldContents;
            }
        }
        return $data;
    }

    // Return subfields for the first found field
    protected function _getFirstFieldSubfields($fieldspecs)
    {
        $data = $this->_getFieldsSubfields($fieldspecs, true);
        if (!empty($data)) {
            return $data[0];
        }
        return '';
    }

    // String of subfields
    protected function _getAllSubfields($field)
    {
        $subfields = '';
        $p = strpos($field, MARCRecord::SUBFIELD_INDICATOR);
        while ($p !== false) {
            $data = substr($field, $p + 2);
            $p2 = strpos($data, MARCRecord::SUBFIELD_INDICATOR);
            if ($p2 !== false) {
                $data = substr($data, 0, $p2);
            }
            if ($subfields) {
                $subfields .= ' ';
            }
            $subfields .= $data;
            $p = strpos($field, MARCRecord::SUBFIELD_INDICATOR, $p + 1);
        }
        return $subfields;
    }

    protected function _setField($field, $value)
    {
        $this->_fields[$field] = $value;
    }
}
