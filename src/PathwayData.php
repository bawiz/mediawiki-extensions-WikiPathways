<?php
/**
 * Copyright (C) 2017  J. David Gladstone Institutes
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
 * @author Anders Riutta <git@andersriutta.com>
 * @author Mark A. Hershberger <mah@nichework.com>
 */
namespace WikiPathways;

use SimpleXMLElement;

/**
 * Object that holds the actual data from a pathway (as stored in GPML)
 */
class PathwayData {
	private $pathway;
	private $gpml;
	private $interactions;
	private $byGraphId;

	/**
	 * Creates an instance of PathwayData, containing
	 * the GPML code parsed as SimpleXml object
	 * @param pathway $pathway to get the data for
	 */
	public function __construct( $pathway ) {
		$this->pathway = $pathway;
		$this->loadGpml();
	}

	/**
	 * Gets the SimpleXML representation of the GPML code
	 *
	 * @return SimpleXMLElement
	 */
	public function getGpml() {
		return $this->gpml;
	}

	/**
	 * Gets the name of the pathway, as stored in GPML
	 *
	 * @return string
	 */
	public function getName() {
		return (string)$this->gpml["Name"];
	}

	/**
	 * Gets the organism of the pathway, as stored in GPML
	 *
	 * @return string
	 */
	public function getOrganism() {
		return (string)$this->gpml["Organism"];
	}

	/**
	 * Gets the interactions
	 * @return array of instances of the Interaction class
	 */
	public function getInteractions() {
		if ( !$this->interactions ) {
			$this->interactions = [];
			foreach ( $this->gpml->Interaction as $line ) {
				$startRef = (string)$line->Graphics->Point[0]['GraphRef'];
				$endRef = (string)$line->Graphics->Point[1]['GraphRef'];
				$typeRef = (string)$line->Graphics->Point[1]['ArrowHead'];
				if ( $startRef && $endRef && $typeRef ) {
					$source = $this->byGraphId[$startRef];
					$target = $this->byGraphId[$endRef];
					// $type = $this->byGraphId[$typeRef];
					if ( $source && $target ) {
						$interaction = new Interaction( $source, $target, $line, $typeRef );
						$this->interactions[] = $interaction;
					}
				}
			}
		}
		return $this->interactions;
	}

	/**
	 * Gets the interactions
	 * @return array of instances of the Interaction class
	 */
	public function getAllAnnotatedInteractions() {
		if ( !$this->interactions ) {
			$this->interactions = [];
			foreach ( $this->gpml->Interaction as $line ) {
				$startRef = (string)$line->Graphics->Point[0]['GraphRef'];
				$points = $line->Graphics->Point;
				$nb = count( $points ) - 1;
				$endRef = (string)$line->Graphics->Point[1]['GraphRef'];
				$typeRef = (string)$line->Graphics->Point[$nb]['ArrowHead'];
				$source = isset( $this->byGraphId[$startRef] ) ? $this->byGraphId[$startRef] : "";
				$target = isset( $this->byGraphId[$endRef] ) ? $this->byGraphId[$endRef] : "";
				$interaction = new Interaction( $source, $target, $line, $typeRef );
				$this->interactions[] = $interaction;
			}
		}
		return $this->interactions;
	}

	/**
	 * Gets the WikiPathways categories that are stored in GPML
	 * Categories are stored as Comments with Source attribute COMMENT_WP_CATEGORY
	 *
	 * @return array
	 */
	public function getWikiCategories() {
		$categories = [];
		foreach ( $this->gpml->Comment as $comment ) {
			if ( $comment['Source'] == COMMENT_WP_CATEGORY ) {
				$cat = trim( (string)$comment );
				if ( $cat ) {
					// Ignore empty category comments
					array_push( $categories, $cat );
				}
			}
		}
		return $categories;
	}

	/**
	 * Gets the WikiPathways description that is stored in GPML
	 * The description is stored as Comment with Source attribute COMMENT_WP_DESCRIPTION
	 *
	 * @return string
	 */
	public function getWikiDescription() {
		foreach ( $this->gpml->Comment as $comment ) {
			if ( $comment['Source'] == COMMENT_WP_DESCRIPTION ) {
				return (string)$comment;
			}
		}
	}

	/**
	 * Get a list of elements of the given type
	 * @param string $name the name of the elements to include
	 * @return string
	 */
	public function getElements( $name ) {
		return $this->getGpml()->$name;
	}

	/**
	 * Get a list of unique elements
	 * @param string $name The name of the elements to include
	 * @param string $uniqueAttribute The attribute of which the value has to be unique
	 * @return array
	 */
	public function getUniqueElements( $name, $uniqueAttribute ) {
		$unique = [];
		foreach ( $this->gpml->$name as $elm ) {
			$key = $elm[$uniqueAttribute];
			$unique[(string)$key] = $elm;
		}
		return $unique;
	}

	/**
	 * Get the unique xrefs for this pathway
	 *
	 * @return array
	 */
	public function getUniqueXrefs() {
		$elements = $this->getElements( 'DataNode' );

		$xrefs = [];

		foreach ( $elements as $elm ) {
			$id = $elm->Xref['ID'];
			$system = $elm->Xref['Database'];
			$ref = new Xref( $id, $system );
			$xrefs[$ref->asText()] = $ref;
		}

		return $xrefs;
	}

	public function getElementsForPublication( $xrefId ) {
		$gpml = $this->getGpml();
		$elements = [];
		foreach ( $gpml->children() as $elm ) {
			foreach ( $elm->BiopaxRef as $ref ) {
				$ref = (string)$ref;
				if ( $xrefId == $ref ) {
					array_push( $elements, $elm );
				}
			}
		}
		return $elements;
	}

	private $pubXRefs;

	public function getPublicationXRefs() {
		return $this->pubXRefs;
	}

	private function findPublicationXRefs() {
		$this->pubXRefs = [];

		$gpml = $this->gpml;

		// Format literature references
		if ( !$gpml->Biopax ) {
			return;
		}

		// $bpChildren = $gpml->Biopax[0]->children("http://www.biopax.org/release/biopax-level2.owl#");
		// only for version >=5.2
		$bpChildren = $gpml->Biopax[0]->children( 'bp', true );

		// BioPAX 2 version of publication xref
		$xrefs2 = $bpChildren->PublicationXRef;

		// BioPAX 3 uses different case
		$xrefs3 = $bpChildren->PublicationXref;
		$this->processXrefs( $xrefs2 );
		$this->processXrefs( $xrefs3 );
	}

	private function processXrefs( $xrefs ) {
		foreach ( $xrefs as $xref ) {
			// Get the rdf:id attribute
			$attr = $xref->attributes( "http://www.w3.org/1999/02/22-rdf-syntax-ns#" );
			// $attr = $xref->attributes('rdf', true); //only for version >=5.2
			$id = (string)$attr['id'];
			$this->pubXRefs[$id] = $xref;
		}
	}

	private function loadGpml() {
		if ( !$this->gpml ) {
			$gpml = $this->pathway->getGpml();

			$this->gpml = new SimpleXMLElement( $gpml );

			// Pre-parse some data
			$this->findPublicationXRefs();
			// Fill byGraphId array
			foreach ( $this->gpml->children() as $elm ) {
				$id = (string)$elm['GraphId'];
				if ( $id ) {
					$this->byGraphId[$id] = $elm;
				}
			}
		}
	}

	public function interactions() {
		$interactions = $this->getInteractionsSoft();
		foreach ( $interactions as $ia ) {
			$table .= "\n|-\n";
			$table .= "| {$ia->getNameSoft()}\n";
			$table .= "|";
			$xrefs = $ia->getPublicationXRefs( $this );
			if ( !$xrefs ) { $xrefs = [];
			}
			foreach ( $xrefs as $ref ) {
				$attr = $ref->attributes( 'rdf', true );
				$table .= "<cite>" . $attr['id'] . "</cite>";
			}
		}
		if ( $table ) {
			$table = "=== Interactions ===\n{|class='wikitable'\n" . $table . "\n|}";
		} else {
			$table = "";
		}
		return $table;
	}
}
