<?php

/**
 * Copyright (C) 2018  J. David Gladstone Institutes
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Thomas Kelder <thomaskelder@gmail.com>
 * @author Alexander Pico <apico@gladstone.ucsf.edu>
 * @author Mark A. Hershberger <mah@nichework.com>
 */
namespace WikiPathways\Statistics\Task;

use WikiPathways\Statistics\Generator;

class XrefCounts
{
    static function run($file, $times) 
    {
        $datasources = array(
         "HMDB" => "Worm", //Use worm database for metabolites (is small, so faster)
         "Ensembl Human" => "Homo sapiens",
        );

        $unmappable = array(
         "HMDB" => array(
          "Entrez Gene", "Ensembl Human", "MGI",
          "SwissProt", "Ensembl", "RefSeq", "Other",
          "UniGene", "HUGO", "", "SGD", "RGD"
         ),
         "Ensembl Human" => array(
          "ChEBI", "HMDB", "PubChem", "Other",
          "CAS", ""
         ),
        );

        $datasourceCounts = array();

        $mappingCache = array();

        foreach($times as $tsCurr) {
            $date = date('Y/m/d', wfTimestamp(TS_UNIX, $tsCurr));
            wfDebugLog(__NAMESPACE__, $date);

            $xrefsPerSpecies = array();

            $pathways = StatPathway::getSnapshot($tsCurr);
            foreach($pathways as $p) {
                $species = $p->getSpecies();
                $gpml = new SimpleXMLElement($p->getGpml());
                foreach($gpml->DataNode as $dn) {
                    $id = (string)$dn->Xref['ID'];
                    $system = (string)$dn->Xref['Database'];
                    if(!$id || !$system) { continue; 
                    }
                    $xrefsPerSpecies[$species][] = "$id||$system";
                }
            }
            foreach(array_keys($xrefsPerSpecies) as $s) {
                $xrefsPerSpecies[$s] = array_unique($xrefsPerSpecies[$s]);
            }

            $counts = array();
            foreach(array_keys($datasources) as $ds) {
                wfDebugLog(__NAMESPACE__, "Mapping $ds");

                if(!array_key_exists($ds, $mappingCache)) {
                    $mappingCache[$ds] = array();
                }
                $myCache = $mappingCache[$ds];

                $mapped = array();
                $db = $datasources[$ds];

                $tomap = array();
                if(in_array($db, array_keys($xrefsPerSpecies))) {
                    $tomap = $xrefsPerSpecies[$db];
                } else {
                    foreach($xrefsPerSpecies as $x) { $tomap += $x; 
                    }
                    $tomap = array_unique($tomap);
                }

                $i = 0;
                foreach($tomap as $x) {
                    $idsys = explode('||', $x);
                    $id = $idsys[0];
                    $system = $idsys[1];
                    if(in_array($system, $unmappable[$ds])) {
                        continue;
                    }
                    if($system == $ds) {
                        $mapped[] = $x;
                        continue;
                    }
                    if(array_key_exists($x, $myCache)) {
                        $mapped += $myCache[$x];
                    } else {
                        $xx = self::mapID($id, $system, $db, $ds);
                        $myCache[$x] = $xx;
                        $mapped += $xx;
                    }
                    $i += 1;
                    if(($i % 10) == 0) { wfDebugLog(__NAMESPACE__, "mapped $i out of " . count($tomap)); 
                    }
                }

                wfDebugLog(__NAMESPACE__, "Mapped: " . count($mapped));
                $counts[$ds] = count($mapped);
            }
            $datasourceCounts[$date] = $counts;
            wfDebugLog(__NAMESPACE__, memory_get_usage() / 1000);
        }

        $fout = fopen($file, 'w');
        fwrite(
            $fout, "date\t" .
            implode("\t", array_fill(0, count($datasources), "number")) . "\n"
        );
        fwrite(
            $fout, "Time\t" .
            implode("\t", array_keys($datasources)) . "\n"
        );

        foreach(array_keys($datasourceCounts) as $date) {
            $values = $datasourceCounts[$date];
            fwrite($fout, $date . "\t" . implode("\t", $values) . "\n");
        }

        fclose($fout);
    }

    static function mapID($id, $system, $db, $ds) 
    {
        global $wpiBridgeUrl;
        if(!$wpiBridgeUrl) { $wpiBridgeUrl = 'http://webservice.bridgedb.org/'; 
        }

        $mapped = array();

        if($db == "metabolites") { $db = "Homo sapiens"; 
        }
        $db_url = urlencode($db);
        $ds_url = urlencode($ds);
        $xd_url = urlencode($system);
        $xi_url = urlencode($id);
        $url =
         "$wpiBridgeUrl$db_url/xrefs/$xd_url/$xi_url?dataSource=$ds_url";
        wfDebugLog(__NAMESPACE__, "opening $url");
        $handle = fopen($url, "r");

        if ($handle) {
            while(!feof($handle)) {
                $line = fgets($handle);
                $cols = explode("\t", $line);
                if(count($cols) == 2) {
                    $mapped[] = $cols[0] . ':' . $cols[1];
                }
            }
            fclose($handle);
        } else {
            wfDebugLog(__NAMESPACE__, "Error getting data from " . $url);
        }
        return $mapped;
    }
}

//Do not register, many requests to the bridgedb web service makes this
//script too slow.
// Generator::registerTask(
// 	'XrefCounts', __NAMESPACE__ . '\XrefCounts::run'
// );
