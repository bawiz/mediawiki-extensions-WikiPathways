<?php
/**
 * Class that represents a Pathway on WikiPathways
 *
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
 * @author Thomas Kelder
 * @author Alexander Pico <apico@gladstone.ucsf.edu>
 * @author Mark A. Hershberger <mah@nichework.com>
 */
namespace WikiPathways;

use Article;
use Exception;
use FSFileBackend;
use Html;
use Linker;
use LocalRepo;
use MWException;
use Revision;
use Title;
use UnregisteredLocalFile;
use WikiPathways\GPML\Converter;

class Pathway {
	public static $ID_PREFIX = 'WP';
	public static $DELETE_PREFIX = "Deleted pathway: ";

	private static $fileTypesConvertableByPathVisio = [
		FILETYPE_GPML => FILETYPE_GPML,
		# FILETYPE_PDF => FILETYPE_PDF,
		FILETYPE_PNG => FILETYPE_PNG,
		# FILETYPE_PWF => FILETYPE_PWF,
		FILETYPE_TXT => FILETYPE_TXT,
		# FILETYPE_BIOPAX => FILETYPE_BIOPAX,
	];

	private static $fileTypes = [
		# FILETYPE_PDF => FILETYPE_PDF,
		# FILETYPE_PWF => FILETYPE_PWF,
		FILETYPE_TXT => FILETYPE_TXT,
		# FILETYPE_BIOPAX => FILETYPE_BIOPAX,
		FILETYPE_IMG => FILETYPE_IMG,
		FILETYPE_GPML => FILETYPE_GPML,
		FILETYPE_PNG => FILETYPE_IMG,
	];

	private static $mimeType = [
		FILETYPE_TXT => "text/plain",
		FILETYPE_IMG => "image/svg+xml",
		FILETYPE_PNG => "image/png",
		FILETYPE_GPML => "text/xml",
	];

	// The title object for the pathway page
	private $pwPageTitle;

	// The pathway identifier
	private $id;

	// The PathwayData for this pathway
	private $pwData;

	// The first revision of the pathway article
	private $firstRevision;

	// The active revision for this instance
	private $revision;

	// The MetaDataCache object that handles the cached title/species
	private $metaDataCache;

	// Manages permissions for private pathways
	private $permissionMgr;

	// GPML Converter
	private $converter;

	/**
	 * Constructor for this class.
	 * @param int $pathId The pathway identifier
	 * @param bool $updateCache whether to update the cache
	 */
	public function __construct( $pathId = null, $updateCache = false ) {
		if ( $pathId === null ) {
			throw new Exception(
				"id argument missing in constructor for Pathway"
			);
		}

		$this->pwPageTitle = Title::newFromText( $pathId, NS_PATHWAY );
		$this->id = $this->pwPageTitle->getDbKey();
		$this->revision = $this->getLatestRevision();
		$this->converter = new Converter( $this->id );
		if ( $updateCache ) {
			$this->updateCache();
		}
	}

	/**
	 * Return pathway id
	 *
	 * @return int
	 */
	public function getIdentifier() {
		return $this->id;
	}

	/**
	 * Return the MW page ID for this pathway
	 *
	 * @return int
	 */
	public function getPageIdDB() {
		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			'page', 'page_id',
			[ 'page_title' => $this->id, 'page_namespace' => NS_PATHWAY ],
			__METHOD__
		);
		foreach ( $res as $row ) {
			$pageId = $row["page_id"];
		}
		return $pageId;
	}

	/**
	 * Constructor for this class.
	 * @param string $name The name of the pathway (without namespace
	 * and species prefix! )
	 * @param string $species The species (full name, e.g. Human)
	 * @param bool $updateCache Whether the cache should be updated if needed
	 * @deprecated This constructor will be removed after the
	 * transision to stable identifiers.
	 * @return Pathway
	 */
	public static function newFromName(
		$name, $species, $updateCache = false
	) {
		wfDebugLog( "Pathway",  "Creating pathway: $name, $species\n" );
		if ( !$name ) {
			throw new Exception(
				"name argument missing in constructor for Pathway"
			);
		}
		if ( !$species ) {
			throw new Exception(
				"species argument missing in constructor for Pathway"
			);
		}

		# general illegal chars

		// ~ $rxIllegal = '/[^' . Title::legalChars() . ']/';
		$rxIllegal = '/[^a-zA-Z0-9_ -]/';
		if ( preg_match( $rxIllegal, $name, $matches ) ) {
			throw new Exception(
				"Illegal character '" . $matches[0] . "' in pathway name"
			);
		}

		return self::newFromTitle( "$species:$name", $updateCache );
	}

	/**
	 * Parse the pathway identifier from the given string or title object.
	 *
	 * @param Title|string $title title to check
	 * @return the identifier, of false if no identifier could be found.
	 */
	public static function parseIdentifier( $title ) {
		if ( $title instanceof Title ) {
			$title = $title->getText();
		}

		$match = [];
		$exists = preg_match( "/" . self::$ID_PREFIX . "\d+/", $title, $match );
		if ( !$exists ) {
			return false;
		}
		return $match[0];
	}

	/**
	 * Get the active revision in the modification
	 * history for this instance. The active revision
	 * is the latest revision by default.
	 *
	 * @return int
	 * @see Pathway::setActiveRevision(revision)
	 */
	public function getActiveRevision() {
		return $this->revision;
	}

	/**
	 * Get the revision number of the latest version
	 * of this pathway
	 *
	 * @return int
	 */
	public function getLatestRevision() {
		return Title::newFromText(
			$this->getIdentifier(), NS_PATHWAY
		)->getLatestRevID();
	}

	/**
	 * Set the active revision for this instance. The active
	 * revision is '0' by default, pointing to the most recent
	 * revision. Set another revision number to retrieve older
	 * versions of this pathway.
	 *
	 * @param int $revision to make active
	 * @param bool $updateCache to update the cache
	 */
	public function setActiveRevision( $revision, $updateCache = false ) {
		if ( $this->revision != $revision ) {
			$this->revision = $revision;
			// Invalidate loaded pathway data
			$this->pwData = null;
			if ( $updateCache ) {
				// Make sure the cache for this revision is up to date
				$this->updateCache();
			}
		}
	}

	/**
	 * Get the PathwayData object that contains the
	 * data stored in the GPML
	 *
	 * @return PathwayData|null
	 */
	public function getPathwayData() {
		// Return null when deleted and not querying an older revision
		if ( $this->isDeleted( false, $this->getActiveRevision() ) ) {
			return null;
		}
		// Only create when asked for ( performance )
		if ( !$this->pwData ) {
			$this->pwData = new PathwayData( $this );
		}
		return $this->pwData;
	}

	/**
	 * Get the permissions manager
	 *
	 * @return PermissionManager
	 */
	public function getPermissionManager() {
		if ( !$this->permissionMgr ) {
			$this->permissionMgr = new PermissionManager(
				$this->getTitleObject()->getArticleId()
			);
		}
		return $this->permissionMgr;
	}

	/**
	 * Make this pathway private for the given user. This
	 * will reset all existing permissions.
	 *
	 * @param User $user to make pathway private to
	 * @throws Exception
	 */
	public function makePrivate( User $user ) {
		$title = $this->getTitleObject();
		if ( $title->userCan( PermissionManager::$ACTION_MANAGE ) ) {
			$mgr = $this->getPermissionManager();
			$private = new PagePermissions( $title->getArticleId() );
			$private->addReadWrite( $user->getId() );
			$private->addManage( $user->getId() );
			$private = PermissionManager::resetExpires( $private );
			$mgr->setPermissions( $private );
		} else {
			throw new Exception(
				"Current user is not allowed to manage permissions for "
				. $this->getIdentifier()
			);
		}
	}

	/**
	 * Find out if this pathway is public (no additional permissions set).
	 *
	 * @return bool
	 */
	public function isPublic() {
		$mgr = $this->getPermissionManager();
		return $mgr->getPermissions() ? false : true;
	}

	/**
	 * Find out if the current user has permissions to view this pathway
	 *
	 * @return bool
	 */
	public function isReadable() {
		return $this->getTitleObject()->userCan( 'read' );
	}

	/**
	 * Utility function that throws an exception if the
	 * current user doesn't have permissions to view the
	 * pathway.
	 *
	 * @throw Exception
	 */
	private function checkReadable() {
		if ( !$this->isReadable() ) {
			throw new Exception(
				"Current user doesn't have permissions to view this pathway"
			);
		}
	}

	/**
	 * Get the MetaDataCache object for this pathway
	 *
	 * @return MetaDataCache
	 */
	private function getMetaDataCache() {
		if ( !$this->metaDataCache && $this->exists() ) {
			$this->metaDataCache = new MetaDataCache( $this );
		}
		return $this->metaDataCache;
	}

	/**
	 * Forces a reload of the cached metadata on the next
	 * time a cached value is queried.
	 */
	private function invalidateMetaDataCache() {
		$this->metaDataCache = null;
	}

	/**
	 * Convert a species code to a species name (e.g. Hs to Human)
	 *
	 * @param string $code coded species
	 * @return string|null
	 */
	public static function speciesFromCode( $code ) {
		$org = Organism::getByCode( $code );
		if ( $org ) {
			return $org->getLatinName();
		}
	}

	/**
	 * Return all pathways
	 *
	 * @param bool|string $species a species, all if false
	 * @return array
	 * @throws Exception
	 */
	public static function getAllPathways( $species = false ) {
		// Check if species is supported
		if ( $species ) {
			if ( !in_array( $species, self::getAvailableSpecies() ) ) {
				throw new Exception( "Species '$species' is not supported." );
			}
		}
		$allPathways = [];
		$dbr = wfGetDB( DB_REPLICA );
		$namespace = NS_PATHWAY;
		$res = $dbr->select(
			'page', 'page_title',
			[ 'page_namespace' => $namespace, 'page_is_redirect' => 0 ], __METHOD__
		);
		foreach ( $res as $row ) {
			try {
				$pathway = self::newFromTitle( $row[0] );
				if ( $pathway->isDeleted() ) {
					// Skip deleted pathways
					continue;
				}
				if ( $species && $pathway->getSpecies() != $species ) {
					// Filter by organism
					continue;
				}
				if ( !$pathway->getTitleObject()->userCanRead() ) {
					// delete this one post 1.19
					continue;
				}
				// if( !$pathway->getTitleObject()->userCan( 'read' )) {
				// // Skip hidden pathways
				// continue;
				// }

				$allPathways[$pathway->getIdentifier()] = $pathway;
			} catch ( Exception $e ) {
				wfDebugLog( "Pathway",  __METHOD__ . ": Unable to add pathway to list: $e" );
			}
		}

		ksort( $allPathways );
		return $allPathways;
	}

	/**
	 * Convert a species name to species code (e.g. Human to Hs)
	 * @param string $species to get code for
	 * @return string
	 */
	public static function codeFromSpecies( $species ) {
		$org = Organism::getByLatinName( $species );
		if ( $org ) {
			return $org->getCode();
		}
	}

	/**
	 * Create a new Pathway from the given title
	 * @param Title $title MW title or the MediaWiki Title object
	 * @param bool $checkCache whether to check (just?) the cache
	 * @throws Exception
	 * @return Pathway
	 */
	public static function newFromTitle( Title $title, $checkCache = false ) {
		// Remove url and namespace from title
		$pathId = self::parseIdentifier( $title );
		return new Pathway( $pathId, $checkCache );
	}

	/**
	 * Create a new Pathway based on a filename
	 * @param Title $title The full title of the pathway file
	 * (e.g. Hs_Apoptosis.gpml), or the MediaWiki Title object
	 * @param bool $checkCache whether to check (just?) the cache
	 * @throws Exception
	 * @return Pathway
	 */
	public static function newFromFileTitle( $title, $checkCache = false ) {
		throw new \MWException( "This is used after all. Change the code or fix "
								. "the use of ereg, etc, here" );
		if ( $title instanceof Title ) {
			$title = $title->getText();
		}
		// "Hs_testpathway.ext"
		if ( ereg( "^( [A-Z][a-z] )_( .+ )\.[A-Za-z]{3,4}$", $title, $regs ) ) {
			$species = self::speciesFromCode( $regs[1] );
			$name = $regs[2];
		}
		if ( !$name || !$species ) {
			throw new Exception( "Couldn't parse file title: $title" );
		}
		return self::newFromTitle( "$species:$name", $checkCache );
	}

	/**
	 * Get all pathways with the given name and species (optional).
	 * @param string $name The name to match
	 * @param string $species The species to match, leave blank to
	 * include all species
	 * @return array of pathway objects for the pathways that match
	 * the name/species
	 */
	public static function getPathwaysByName( $name, $species = '' ) {
		$pages = MetaDataCache::getPagesByCache(
			MetaDataCache::$FIELD_NAME, $name
		);
		$pathways = [];
		foreach ( $pages as $page ) {
			$pathway = self::newFromTitle( Title::newFromId( $page ) );
			if ( !$species || $pathway->getSpecies() == $species ) {
				// Don't add deleted pathways
				if ( !$pathway->isDeleted() ) {
					$pathways[] = $pathway;
				}
			}
		}
		return $pathways;
	}

	/**
	 * Get the full url to the pathway page
	 * @return string
	 */
	public function getFullURL() {
		return $this->getTitleObject()->getFullURL();
	}

	/**
	 * Get the MediaWiki Title object for the pathway page
	 * @return Title
	 */
	public function getTitleObject() {
		return $this->pwPageTitle;
	}

	/**
	 * Returns a list of species
	 * @return array
	 */
	public static function getAvailableSpecies() {
		return array_keys( Organism::listOrganisms() );
	}

	/**
	 * @deprecated this won't work with stable IDs
	 */
	private static function nameFromTitle( $title ) {
		$parts = explode( ':', $title );

		if ( count( $parts ) < 2 ) {
			throw new Exception( "Invalid pathway article title: $title" );
		}
		return array_pop( $parts );
	}

	/**
	 * @deprecated this won't work with stable IDs
	 */
	private static function speciesFromTitle( $title ) {
		$parts = explode( ':', $title );

		if ( count( $parts ) < 2 ) {
			throw new Exception( "Invalid pathway article title: $title" );
		}
		$species = array_slice( $parts, -2, 1 );
		$species = array_pop( $species );
		$species = str_replace( '_', ' ', $species );
		return $species;
	}

	/**
	 * Get or set the pathway name (without namespace or species prefix)
	 * @param string $name changes the name to this value if not null
	 * @return string the name of the pathway
	 * @deprecated use #getName instead! Name can only be set by
	 * editing the GPML.
	 */
	public function name( $name = null ) {
		if ( $name ) {
			throw new Exception( "Species can only be set by editing GPML" );
		}
		return $this->getName();
	}

	/**
	 * Temporary function used during the transition
	 * to stable identifiers. This method does not return
	 * the cached name, but the name as it is in the pathway page title
	 *
	 * @return string
	 */
	public function getNameFromTitle() {
		return self::nameFromTitle( $this->getTitleObject() );
	}

	/**
	 * Temporary function used during the transition
	 * to stable identifiers. This method does not return
	 * the cached species, but the species as it is in the pathway page title
	 *
	 * @return array
	 */
	public function getSpeciesFromTitle() {
		return self::speciesFromTitle( $this->getTitleObject() );
	}

	/**
	 * Get the pathway name (without namespace or species prefix).
	 * This method will not load the GPML, but use the
	 * metadata cache for performance.
	 * @param bool $textForm or no
	 * @return string
	 */
	public function getName( $textForm = true ) {
		if ( $this->exists() ) {
			// Only use cache if this pathway exists
			return $this->getMetaDataCache()->getValue(
				MetaDataCache::$FIELD_NAME
			);
		} else {
			return "";
		}
	}

	/**
	 * Get the species for this pathway.
	 * This method will not load the GPML, but use the
	 * metadata cache for performance.
	 * @return string
	 */
	public function getSpecies() {
		// Only use cache if this pathway exists
		if ( $this->exists() ) {
			return $this->getMetaDataCache()->getValue(
				MetaDataCache::$FIELD_ORGANISM
			)->getText();
		} else {
			return "";
		}
	}

	/**
	 * Get the species for this pathway.
	 * This method will not load the GPML, but use the
	 * metadata cache for performance.
	 * @return string
	 */
	public function getSpeciesAbbr() {
		if ( $this->exists() ) {
			// Only use cache if this pathway exists
			$species = $this->getMetaDataCache()->getValue(
				MetaDataCache::$FIELD_ORGANISM
			);
			$m = [];
			preg_match( "/(\S)\S*\s*(\S)/", $species, $m );
			if ( count( $m ) === 3 ) {
				return $m[1] . $m[2];
			} else {
				return "";
			}
		} else {
			return "";
		}
	}

	/**
	 * Get the unique xrefs in this pathway.
	 * This method will not load the GPML, but use the
	 * metadata cache for performance.
	 * @return array
	 */
	public function getUniqueXrefs() {
		if ( $this->exists() ) {
			// Only use cache if this pathway exists
			$xrefStr = $this->getMetaDataCache()->getValue(
				MetaDataCache::$FIELD_XREFS
			);
			$xrefStr = explode( MetaDataCache::$XREF_SEP, $xrefStr );
			$xrefs = [];
			foreach ( $xrefStr as $s ) { $xrefs[$s] = Xref::fromText( $s );
			}
			return $xrefs;
		} else {
			return [];
		}
	}

	/**
	 * Get or set the pathway species
	 * @param string $species changes the species to this value if not null
	 * @return the species of the pathway
	 * @deprecated use #getSpecies instead! Species can only be set by
	 * editing the GPML.
	 */
	public function species( $species = null ) {
		if ( $species ) {
			throw new Exception( "Species can only be set by editing GPML" );
		}
		return $this->getSpecies();
	}

	/**
	 * Get the species code (abbrevated species name, e.g. Hs for Human)
	 * @return string
	 */
	public function getSpeciesCode() {
		$org = Organism::getByLatinName( $this->getSpecies() );
		if ( $org ) {
			return $org->getCode();
		}
	}

	/**
	 * Check if this pathway exists in the database
	 * @return true if the pathway exists, false if not
	 */
	public function exists() {
		$title = $this->getTitleObject();
		return !is_null( $title ) && $title->exists();
	}

	/**
	 * Find out if there exists a pathway that has a
	 * case insensitive match with the name of this
	 * pathway object. This method is necessary to perform
	 * a case insensitive search on pathways, since MediaWiki
	 * titles are case sensitive.
	 * @return Title object representing the page title of
	 * the found pathway in the proper case, or null if no
	 * matching pathway was found
	 */
	public function findCaseInsensitive() {
		$title = strtolower( $this->getTitleObject()->getDbKey() );
		$dbr = wfGetDB( DB_REPLICA );
		$namespace = NS_PATHWAY;
		$res = $dbr->select(
			"page", "page_id",
			[
				"page_namespace" => $namespace,
				"page_is_redirect" => 0,
				"LOWER( page_title )" => $title
			], __METHOD__ );
		$title = null;
		if ( $res->numRows() > 0 ) {
			$row = $dbr->fetchRow( $res );
			$title = Title::newFromID( $row[0] );
		}
		return $title;
	}

	/**
	 * Get the GPML code for this pathway (the active revision will be
	 * used, see Pathway::getActiveRevision)
	 *
	 * @return string
	 */
	public function getGpml() {
		$this->checkReadable();
		$gpmlTitle = $this->getTitleObject();
		$gpmlRef = Revision::newFromTitle( $gpmlTitle, $this->revision );

		return $gpmlRef == null ? "" : $gpmlRef->getSerializedData();
	}

	/**
	 * Get the JSON for this pathway, as a string (the active revision will be
	 * used, see Pathway::getActiveRevision)
	 * Gets the JSON representation of the GPML code,
	 * formatted to match the structure of SVG,
	 * as a string.
	 * TODO: we aren't caching this
	 */
	public function getPvjson() {
		wfDebugLog( "Pathway",  "getPvjson() called\n" );

		if ( isset( $this->pvjson ) ) {
			wfDebugLog( "Pathway",  "Returning pvjson from memory\n" );
			return $this->pvjson;
		}

		$file = $this->getFileLocation( FILETYPE_JSON, false );
		if ( $file && file_exists( $file ) ) {
			wfDebugLog( "Pathway",  "Returning pvjson from cache $file\n" );
			return file_get_contents( $file );
		}

		$gpmlPath = $this->getFileLocation( FILETYPE_GPML, false );
		$identifier = $this->getIdentifier();
		$version = $this->getActiveRevision();
		$organism = $this->getSpecies();

		if ( !$gpmlPath ) {
			error_log( "No file for GPML!" );
			return null;
		}

		if ( !file_exists( $gpmlPath ) ) {
			$this->updateCache( FILETYPE_GPML );
		}

		$this->pvjson = $this->converter->gpml2pvjson(
			file_get_contents( $gpmlPath ),
			[ "identifier" => $identifier,
			  "version" => $version,
			  "organism" => $organism ]
		);
		if ( $this->pvjson ) {
			wfDebugLog( "Pathway",  "Converted gpml to pvjson\n" );
			$this->savePvjsonCache();
			return $this->pvjson;
		}
		$err = error_get_last();
		error_log( "Trouble converting $gpmlPath: {$err['message']}" );
		return null;
	}

	/**
	 * Get the SVG for the given JSON
	 * @return string
	 */
	public function getSvg() {
		wfDebugLog( "Pathway",  "getSvg() called\n" );

		if ( isset( $this->svg ) ) {
			wfDebugLog( "Pathway",  "Returning svg from memory\n" );
			return $this->svg;
		}

		$file = $this->getFileLocation( FILETYPE_IMG, false );
		if ( $file && file_exists( $file ) ) {
			wfDebugLog( "Pathway",  "Returning svg from cache $file\n" );
			return file_get_contents( $file );
		}

		wfDebugLog( "Pathway",  "need to get pvjson in order to get svg\n" );
		$pvjson = $this->getPvjson();
		wfDebugLog( "Pathway",  "got pvjson in process of getting svg\n" );
		$svg = $this->converter->getpvjson2svg( $pvjson, [ "static" => false ] );
		wfDebugLog( "Pathway",  "got svg\n" );
		$this->svg = $svg;
		return $svg;
	}

	/**
	 * Check if PathVisio-Java can convert from GPML to the given file type
	 *
	 * @param string $fileType to check
	 * @return bool
	 */
	public static function isConvertableByPathVisio( $fileType ) {
		return isset( self::$fileTypesConvertableByPathVisio[ $fileType ] );
	}

	/**
	 * Check if the given file type is valid (a pathway can
	 * be converted to this file type)
	 */
	public static function isValidFileType( $fileType ) {
		return in_array( $fileType, array_keys( self::$fileTypes ) );
	}

	/**
	 * Get the filename of a cached file following the naming conventions
	 * @param string $fileType the file type to get the name for (one of the FILETYPE_* constants)
	 */
	public function getFileName( $fileType ) {
		return $this->getFileTitle( $fileType )->getDBKey();
	}

	private function getSubdir() {
		static $hash;
		if ( !$hash ) {
			$hash = md5( $this->getIdentifier() );
		}
		$subdir = substr( $hash, 0, 1 ) . '/' . substr( $hash, 0, 2 );
		return $subdir;
	}

	private function getSubdirAndFile( $fileType ) {
		$dir = $this->getSubdir();
		$fn = $this->getFileName( $fileType );

		return "$dir/$fn";
	}

	/**
	 * Gets the path that points to the cached file
	 * @param string $fileType the file type to get the name for (one of the FILETYPE_* constants)
	 * @param bool $updateCache whether to update the cache (if needed) or not
	 */
	public function getFileLocation( $fileType, $updateCache = true ) {
		// Make sure to have up to date version
		if ( $updateCache ) {
			$this->updateCache( $fileType );
		}
		global $wpiFileCache;
		return "$wpiFileCache/" . $this->getSubdirAndFile( $fileType );
	}

	/**
	 * Get a LocalFile object
	 *
	 * @param string $fileType to get
	 * @param bool $updateCache or not
	 * @return LocalFile
	 */
	public function getFileObj( $fileType, $updateCache = true ) {
		if ( $updateCache ) {
			// Make sure to have up to date version
			$this->updateCache( $fileType );
		}
		$fn = $this->getFileName( $fileType );
		return wfLocalFile( $fn );
	}

	/**
	 * Gets the url that points to the the cached file
	 *
	 * @param string $fileType the file type to get the name for (one of the FILETYPE_* constants)
	 * @param bool $updateCache whether to update the cache (if needed) or not
	 * @return string
	 */
	public function getFileURL( $fileType, $updateCache = true ) {
		global $IP, $wpiFileCache;
		$cachePath = substr( $wpiFileCache, strlen( $IP ) );
		return "$cachePath/" . $this->getSubdirAndFile( $fileType, $updateCache );
	}

	/**
	 * Creates a MediaWiki title object that represents the article in
	 * the NS_IMAGE namespace for cached file of given file type.
	 * There is no guarantee that an article exists for each filetype.
	 * Currently articles exist for FILETYPE_IMG (.svg articles in
	 * the NS_IMAGE namespace)
	 *
	 * @param string $fileType to get
	 * @return Title
	 * @throws Exception
	 */
	public function getFileTitle( $fileType ) {
		// Append revision number if it's not the most recent
		$refStuffix = '';
		if ( $this->revision ) {
			$refStuffix = "_" . $this->revision;
		}
		return Title::newFromText(
			$this->getIdentifier() . $refStuffix . "." . $fileType,
			NS_FILE
		);
	}

	/**
	 * Get the title object for the image page.
	 * Equivalent to <code>getFileTitle( FILETYPE_IMG )</code>
	 *
	 * @return Title
	 */
	public function getImageTitle() {
		return $this->getFileTitle( FILETYPE_IMG );
	}

	/**
	 * Get the prefix part of the filename, with all illegal characters
	 * filtered out (e.g. Hs_Apoptosis for Human:Apoptosis)
	 *
	 * @return string
	 * @throws Exception
	 */
	public function getFilePrefix() {
		$prefix = $this->getSpeciesCode() . "_" . $this->getName();
		/*
		 * Filter out illegal characters, and try to make a legible name
		 * out of it. We'll strip some silently that Title would die on.
		 */
		$filtered = preg_replace(
			"/[^".Title::legalChars()."]|:/", '-', $prefix
		);
		/*
		 * Filter out additional illegal character that shouldn't be
		 * in a file name
		 */
		$filtered = preg_replace( "/[\/\?\<\>\\\:\*\|\[\]]/", '-', $prefix );

		$title = Title::newFromText( $filtered, NS_FILE );
		if ( !$title ) {
			throw new Exception(
				"Invalid file title for pathway " + $filtered
			);
		}
		return $title->getDBKey();
	}

	/**
	 * Get first revision for current title
	 *
	 * @return int
	 */
	public function getFirstRevision() {
		if ( $this->exists() && !$this->firstRevision ) {
			$revs = Revision::fetchAllRevisions( $this->getTitleObject() );
			$revs->seek( $revs->numRows() - 1 );
			$row = $revs->fetchRow();
			$this->firstRevision = Revision::newFromId( $row['rev_id'] );
		}
		return $this->firstRevision;
	}

	/**
	 * Get the revision id for the first revision after the given one.
	 *
	 * @param int $rev revisions number
	 * @return int
	 */
	public function getFirstRevisionAfterRev( $rev ) {
		$rev = Revision::newFromId( $rev );
		return $rev->getNext();
	}

	/**
	 * Get revision id for the last revision prior to specified datae.
	 * This is useful for generating statistics over the history of
	 * the archive.
	 *
	 * @param string $timestamp for date
	 * @return null|Revision
	 */
	public function getLastRevisionPriorToDate( $timestamp ) {
		/* This code should be more efficient than what was here, but
		 * it is untested.  Leaving it here because I couldn't find
		 * any use of this function. */
		$rev = Revision::loadFromTimestamp( wfGetDB( DB_REPLICA ),
											$this->getTitleObject(), $timestamp );
		return $rev->getPrevious();

		$revs = Revision::fetchAllRevisions( $this->getTitleObject() );
		foreach ( $revs as $eachRev ) {
			$revTime = $eachRev->rev_timestamp;
			print "$revTime\n";
			if ( $revTime < $timestamp ) {
				return $eachRev;
			}
		}
		return null;
	}

	/**
	 * Creates a new pathway on the wiki. A unique identifier will be
	 * generated for the pathway.
	 *
	 * @param string $gpmlData The GPML code for the pathway
	 * @param string $description string
	 * @return The Pathway object for the created pathway
	 */
	public static function createNewPathway(
		$gpmlData, $description = "New pathway"
	) {
		$newId = self::generateUniqueId();
		$pathway = new Pathway( $newId, false );
		if ( $pathway->exists() ) {
			throw new Exception(
				"Unable to generate unique id, $newId already exists"
			);
		}
		$pathway->updatePathway( $gpmlData, $description );
		$pathway = new Pathway( $newId );
		return $pathway;
	}

	private static function generateUniqueId() {
		// Get the highest identifier
		$dbr = wfGetDB( DB_REPLICA );
		$namespace = NS_PATHWAY;
		$prefix = self::$ID_PREFIX;
		$likePrefix = $dbr->buildLike(
			$prefix . $dbr->anyChar() . $dbr->anyString()
		);

		$res = $dbr->select(
			"page", "page_title",
			[
				'page_namespace' => $namespace, 'page_is_redirect' => 0,
				'page_title' . $likePrefix
			], __METHOD__,
			[
				'ORDER BY' => [
					'length( page_title ) DESC',
					'page_title DESC'
				],
				'OFFSET' => 0,
				'LIMIT' => 1
			]
		);
		$row = $dbr->fetchObject( $res );
		if ( $row ) {
			$lastid = $row->page_title;
		} else {
			$lastid = self::$ID_PREFIX . "0";
		}
		$lastidNum = substr( $lastid, 2 );

		$res2 = $dbr->select(
			'archive', 'ar_title',
			[ 'ar_namespace' => $namespace, 'ar_title'. $likePrefix ],
			__METHOD__,
			[
				'ORDER BY' => [
					'length( ar_title ) DESC',
					'ar_title DESC'
				],
				'OFFSET' => 0,
				'LIMIT' => 1
			]
		);
		$row2 = $dbr->fetchObject( $res2 );
		if ( $row2 ) {
			$lastid2 = $row2->page_title;
		} else {
			$lastid2 = self::$ID_PREFIX . "0";
		}
		$lastidNum2 = substr( $lastid2, 2 );

		// Pick largest WPID
		if ( (int)$lastidNum2 > (int)$lastidNum ) {
			$lastidNum = $lastidNum2;
		}
		$newidNum = $lastidNum + 1;
		$newid = self::$ID_PREFIX . $newidNum;
		return $newid;
	}

	/**
	 * This is probably a bad SHIM since I'm depending on transforming
	 * GPML to wikitext and then using MW to parse that.  That's
	 * probably not what I should do, but to make it work, I need this
	 * signal.
	 */
	public static $InternalUpdate = false;
	/**
	 * Update the pathway with the given GPML code
	 * @param string $gpmlData The GPML code that contains the updated
	 * pathway data
	 * @param string $description A description of the changes
	 * @return true for success
	 */
	public function updatePathway( $gpmlData, $description ) {
		global $wgUser;

		$gpml = new GPML\Content( $gpmlData );
		// First validate the gpml
		if ( !$gpml->isValid() ) {
			throw new Exception( $gpml->getValidationError() );
		}

		$gpmlTitle = $this->getTitleObject();

		// Check permissions
		if ( is_null( $wgUser ) || !$wgUser->isLoggedIn() ) {
			throw new Exception( "User is not logged in" );
		}
		if ( $wgUser->isBlocked() ) {
			throw new Exception( "User is blocked" );
		}
		if ( !$gpmlTitle->userCan( 'edit' ) ) {
			throw new Exception(
				"User has wrong permissions to edit the pathway"
			);
		}
		if ( wfReadOnly() ) {
			throw new Exception( "Database is read-only" );
		}

		// Force update from the newest version
		$gpmlArticle = new Article( $gpmlTitle, 0 );
		if ( !$gpmlTitle->exists() ) {
			// This is a new pathway, add the author to the watch list
			$gpmlArticle->doWatch();
		}

		self::$InternalUpdate = true;
		$success = $gpmlArticle->doEditContent( $gpml, $description, EDIT_INTERNAL );
		self::$InternalUpdate = false;
		if ( $success ) {
			// Force reload of data
			$this->setActiveRevision( $this->getLatestRevision() );
			// Update metadata cache
			$gpmlArticle->doPurge();
		} else {
			throw new Exception( "Unable to save GPML." );
		}
		return $success;
	}

	/**
	 * Parse a mediawiki page that contains a pathway list.  Assumes
	 * one pathway per line, invalid lines will be ignored.
	 *
	 * @param string $listPage title
	 * @return array
	 */
	public static function parsePathwayListPage( $listPage ) {
		$listRev = Revision::newFromTitle( Title::newFromText( $listPage ), 0 );
		if ( $listRev != null ) {
			$lines = explode( "\n", $listRev->getContent()->getNativeData() );
		} else {
			$lines = [];
		}
		$pathwayList = [];

		// Try to parse a pathway from each line
		foreach ( $lines as $title ) {
			// Regex to fetch title from "* [[title|...]]"
			// \*\ *\[\[( .* )\]\]
			$title = preg_replace( '/\*\ *\[\[(.*)\]\]/', '$1', $title );
			$title = Title::newFromText( $title );
			if ( $title != null ) {
				try {
					$article = new Article( $title );
					// Follow redirects
					if ( $article->isRedirect() ) {
						$redirect = $article->fetchContent();
						$title = Title::newFromRedirect( $redirect );
					}
					// If pathway creation works and the pathway
					// exists, add to array
					$pathway = self::newFromTitle( $title );
					if ( !is_null( $pathway ) && $pathway->exists() ) {
						$pathwayList[] = $pathway;
					}
				} catch ( Exception $e ) {
					// Ignore the pathway
				}
			}
		}
		return $pathwayList;
	}

	static public $gpmlSchemas = [
		"http://genmapp.org/GPML/2007" => "GPML2007.xsd",
		"http://genmapp.org/GPML/2008a" => "GPML2008a.xsd",
		"http://genmapp.org/GPML/2010a" => "GPML2010a.xsd",
		"http://pathvisio.org/GPML/2013a" => "GPML2013a.xsd"
	];

	/**
	 * Get the schema from the XML NS
	 *
	 * @param string $xmlNs the namespace
	 * @return string
	 */
	public static function getSchema( $xmlNs ) {
		if ( isset( self::$gpmlSchemas[$xmlNs] ) ) {
			return self::$gpmlSchemas[$xmlNs];
		}
	}

	/**
	 * Revert this pathway to an old revision
	 * @param int $oldId The id of the old revision to revert the
	 * pathway to
	 * @throws Exception
	 */
	public function revert( $oldId ) {
		global $wgUser, $wgLang;
		$rev = Revision::newFromId( $oldId );
		$gpml = $rev->getSerializedData();
		if ( self::isDeletedMark( $gpml ) ) {
			throw new Exception(
				"You are trying to revert to a deleted version of the pathway. "
				. "Please choose another version to revert to."
			);
		}
		if ( $gpml ) {
			$usr = Linker::userLink(
				$wgUser->getId(), $wgUser->getName()
			);
			$date = $wgLang->timeanddate( $rev->getTimestamp(), true );
			$this->updatePathway(
				$gpml, "Reverted to version '$date' by $usr"
			);
		} else {
			throw new Exception( "Unable to get gpml content" );
		}
	}

	/**
	 * Check whether this pathway is marked as deleted.
	 * @param bool $useCache Set to false to use actual page text to
	 * check if the pathway is deleted. If true or not specified, the
	 * cache will be used.
	 * @param int|bool $revision Set to an int if you want to check if the
	 * given revision is a deletion mark (not the newest revision).
	 * @return bool
	 */
	public function isDeleted( $useCache = true, $revision = false ) {
		if ( !$this->exists() ) {
			return false;
		}
		if ( $useCache && !$revision ) {
			$deprev = $this->getMetaDataCache()->getValue(
				MetaDataCache::$FIELD_DELETED
			);
			if ( $deprev ) {
				$rev = $this->getActiveRevision();
				if ( $rev == 0 || $rev == $deprev->getText() ) {
					return true;
				}
			}

			return false;
		} else {
			if ( !$revision ) {
				$revision = $this->getLatestRevision();
			}
			$text = Revision::newFromId( $revision )->getSerializedData();
			return self::isDeletedMark( $text );
		}
	}

	/**
	 * Check if the given text marks the pathway as deleted.
	 *
	 * @param string $text to check
	 * @return bool
	 * @fixme Use native MW deletion?
	 */
	public static function isDeletedMark( $text ) {
		return substr( $text, 0, 9 ) == "{{deleted";
	}

	/**
	 * Delete this pathway. The pathway will not really deleted,
	 * instead, the pathway page will be marked as deleted by replacing the GPML
	 * with a deletion mark.
	 *
	 * @param string $reason to give for deletion
	 * @fixme why not really delete?
	 */
	public function delete( $reason = "" ) {
		global $wgUser;
		if ( $this->isDeleted( false ) ) {
			// Already deleted, nothing to do
			return;
		}

		// Check permissions
		if ( is_null( $wgUser ) || !$wgUser->isLoggedIn() ) {
			throw new Exception( "User is not logged in" );
		}
		if ( $wgUser->isBlocked() ) {
			throw new Exception( "User is blocked" );
		}
		if ( !$this->getTitleObject()->userCan( 'delete' ) ) {
			throw new Exception(
				"User doesn't have permissions to mark this pathway as deleted"
			);
		}
		if ( wfReadOnly() ) {
			throw new Exception( "Database is read-only" );
		}

		$article = new Article( $this->getTitleObject(), 0 );
		// Temporarily disable GPML validation hook
		global $wpiDisableValidation;
		$wpiDisableValidation = true;

		$succ = $article->doEdit(
			"{{deleted|$reason}}", self::$DELETE_PREFIX . $reason
		);
		if ( $succ ) {
			// Update metadata cache
			$this->invalidateMetaDataCache();

			// Clean up file cache
			$this->clearCache( null );
		} else {
			throw new Exception(
				"Unable to mark pathway deleted, are you logged in?"
			);
		}
	}

	/**
	 * Delete a MediaWiki article
	 *
	 * @param Title $title to delete
	 * @param string $reason given
	 */
	public static function deleteArticle(
		Title $title, $reason='not specified'
	) {
		global $wgUser;

		$article = new Article( $title );

		if ( wfRunHooks( 'ArticleDelete', [ $article, &$wgUser, &$reason ] ) ) {
			$article->doDeleteArticle( $reason );
			wfRunHooks(
				'ArticleDeleteComplete', [ $article, &$wgUser, $reason ]
			);
		}
	}

	/**
	 * Checks whether the cached files are up-to-data and updates them
	 * if neccesary
	 * @param string $fileType The file type to check the cache for (one of
	 * FILETYPE_* constants) or null to check all files
	 */
	public function updateCache( $fileType = null ) {
		wfDebugLog( "Pathway",  "updateCache called for filetype $fileType\n" );
		// Make sure to update GPML cache first
		if ( $fileType !== FILETYPE_GPML ) {
			$this->updateCache( FILETYPE_GPML );
		}

		global $wpiFileCache;
		wfMkdirParents( $wpiFileCache . '/' . $this->getSubdir(), null, __METHOD__ );
		if ( $fileType === null ) {
			// Update all
			foreach ( self::$fileTypes as $type ) {
				$this->updateCache( $type );
			}
			return;
		}
		if ( $this->isOutOfDate( $fileType ) ) {
			wfDebugLog( "Pathway",  "\t->Updating cached file for $fileType\n" );
			switch ( $fileType ) {
				case FILETYPE_GPML:
					$this->saveGpmlCache();
					break;
				case FILETYPE_JSON:
					$this->savePvjsonCache();
					break;
				case FILETYPE_IMG:
					$this->saveSvgCache();
					break;
				default:
					$this->saveConvertedByPathVisioCache( $fileType );
			}
		}
	}

	private static $repo;
	private function setupRepo() {
		global $wgUploadPath;
		global $wgUploadDirectory;

		self::$repo = new LocalRepo( [
			"name" => "PathwayRepo",
			"backend" => new FSFileBackend( [
				"name" => "PathwayBackend",
				"domainId" => "wikipathways",
				'basePath' => $wgUploadDirectory . "/wikipathways"
			] ),
			"url" => $wgUploadPath . "/wikipathways"
		] );
	}

	private function getRepo() {
		return self::$repo;
	}

	/**
	 * Get the MW image
	 *
	 * @param string $type of image
	 * @return UnregisteredLocalFile
	 */
	protected function getImgObject( $type ) {
		$this->setupRepo();
		return new UnregisteredLocalFile(
			false, $this->getRepo(),
			$this->getFileLocation( $type ), self::$mimeType[$type]
		);
	}

	/**
	 * Get the file object for a pathway
	 *
	 * @return LocalFile
	 */
	public function getImage() {
		// This makes it more in a wiki way.
		$img = $this->getImgObject( FILETYPE_IMG );
		$path = $this->getFileLocation( FILETYPE_IMG );

		if ( !file_exists( $path ) ) {
			/* Avoid calling this unless we need to */
			$this->updateCache( FILETYPE_IMG );
		}

		return $img;
	}

	/**
	 * Clear all cached files
	 * @param string $fileType The file type to remove the cache for (
	 * one of FILETYPE_* constants ) or null to remove all files
	 */
	public function clearCache( $fileType = null ) {
		if ( !$fileType ) {
			// Update all
			$this->clearCache( FILETYPE_PNG );
			$this->clearCache( FILETYPE_GPML );
			$this->clearCache( FILETYPE_IMG );
		} else {
			$file = $this->getFileLocation( $fileType, false );
			if ( file_exists( $file ) ) {
				// Delete the cached file
				unlink( $file );
			}
		}
	}

	private function ensureDir( $filename ) {
		$dir = dirname( $filename );
		if ( !file_exists( $dir ) ) {
			wfMkdirParents( $dir );
		};
	}

	// Check if the cached version of the GPML data derived file is out of date
	private function isOutOfDate( $fileType ) {
		wfDebugLog( "Pathway",  "isOutOfDate for $fileType\n" );

		$gpmlTitle = $this->getTitleObject();
		$gpmlRev = Revision::newFromTitle( $gpmlTitle );
		if ( $gpmlRev ) {
			$gpmlDate = $gpmlRev->getTimestamp();
		} else {
			$gpmlDate = -1;
		}

		$file = $this->getFileObj( $fileType, false );

		if ( $file->exists() ) {
			$fmt = wfTimestamp( TS_MW, filemtime( $file ) );
			wfDebugLog( "Pathway",  "\tFile exists, cache: $fmt, gpml: $gpmlDate\n" );
			return $fmt < $gpmlDate;
		} elseif ( $fileType === FILETYPE_GPML ) {
			$output = $this->getFileLocation( FILETYPE_GPML, false );
			$rev = Revision::newFromTitle(
				$this->getTitleObject(), false, Revision::READ_LATEST
			);
			if ( !is_object( $rev ) ) {
				return true;
			}

			self::ensureDir( $output );
			file_put_contents( $output, $rev->getContent()->getNativeData() );
			return false;
		} else {
			// No cached version yet, so definitely out of date
			wfDebugLog( "Pathway",  "\tFile doesn't exist\n" );
			return true;
		}
	}

	/**
	 * The the last time the GPML was modified
	 *
	 * @return string
	 */
	public function getGpmlModificationTime() {
		$gpmlTitle = $this->getTitleObject();
		$gpmlRev = Revision::newFromTitle( $gpmlTitle );
		if ( $gpmlRev ) {
			$gpmlDate = $gpmlRev->getTimestamp();
		} else {
			throw new Exception( "No GPML page" );
		}
		return $gpmlDate;
	}

	/**
	 * Save a cached version of a filetype to be converted
	 * from GPML, when the conversion is done by PathVisio.
	 */
	private function saveConvertedByPathVisioCache( $fileType ) {
		# Convert gpml to fileType
		$gpmlFile = realpath( $this->getFileLocation( FILETYPE_GPML ) );
		$conFile = $this->getFileLocation( $fileType, false );
		$dir = dirname( $conFile );
		wfDebugLog( "Pathway",  "Saving $gpmlFile to $fileType in $conFile" );

		if ( !is_dir( $dir ) && !wfMkdirParents( $dir ) ) {
			throw new MWException( "Couldn't make directory: $dir" );
		}
		if ( self::isConvertableByPathVisio( $fileType ) ) {
			self::convertWithPathVisio( $gpmlFile, $conFile );
		} else {
			throw new MWException( "PathVisio couldn't convert file of type \"$fileType\"" );
		}
		return $conFile;
	}

	/**
	 * Convert the given GPML file to another
	 * file format, using PathVisio-Java. The file format will be determined by the
	 * output file extension.
	 *
	 * @param string $gpmlFile source
	 * @param string $outFile destination
	 * @return bool
	 */
	public static function convertWithPathVisio( $gpmlFile, $outFile ) {
		global $wgMaxShellMemory;

		$gpmlFile = realpath( $gpmlFile );

		self::ensureDir( $outFile );
		$basePath = WPI_SCRIPT_PATH;
		// Max script memory on java program in megabytes
		$maxMemoryM = intval( $wgMaxShellMemory / 1024 );

		$cmd = "java -Xmx{$maxMemoryM}M -jar "
			 . "$basePath/bin/pathvisio_core.jar "
			 . "'$gpmlFile' '$outFile' 2>&1";
		wfDebugLog( "Pathway",  "CONVERTER: $cmd\n" );
		$msg = wfShellExec( $cmd, $status, [], [ 'memory' => 0 ] );

		if ( $status != 0 ) {
			throw new MWException(
				"Unable to convert to $outFile:\n\n"
				. "Status: $status\n\nMessage: $msg\n\n"
				. "Command: $cmd"
			);
			wfDebugLog( "Pathway",
				"Unable to convert to $outFile:"
				. "Status: $status   Message:$msg  "
				. "Command: $cmd"
			);
		}
		return true;
	}

	private function saveGpmlCache() {
		wfDebugLog( "Pathway",  "saveGpmlCache() called\n" );
		$gpml = $this->getGpml();
		// Only write cache if there is GPML
		if ( $gpml ) {
			$file = $this->getFileObj( FILETYPE_GPML, false );
			$file->publish( $gpml );
			wfDebugLog( "Pathway",  "GPML CACHE SAVED: " . $file->getPath() );
		}
	}

	private function savePvjsonCache() {
		wfDebugLog( "Pathway",  "savePvjsonCache() called\n" );
		// This function is always called when GPML is converted to pvjson; which is not the case for SVG.
		$pvjson = $this->pvjson;

		if ( !$pvjson ) {
			$pvjson = $this->getPvjson();
		}

		if ( !$pvjson ) {
			wfDebugLog( "Pathway",  "Invalid pvjson, so cannot savePvjsonCache." );
			return;
		}

		$file = $this->getFileLocation( FILETYPE_JSON, false );
		wfDebugLog( "Pathway",  "savePvjsonCache: Need to write pvjson to $file\n" );
		self::writeFile( $file, $pvjson );

		if ( !file_exists( $file ) ) {
			throw new Exception( "Unable to save pvjson" );
		}
		wfDebugLog( "Pathway",  "PVJSON CACHE SAVED: $file\n" );
	}

	private function saveSvgCache() {
		wfDebugLog( "Pathway",  "saveSvgCache() called\n" );
		$gpmlPath = $this->getFileLocation( FILETYPE_GPML, false );
		if ( !$gpmlPath || !file_exists( $gpmlPath ) ) {
			throw new MWException( "saveSvgCache() failed: GPML unavailable." );
		}
		$svg = $this->getSvg();
		if ( !$svg ) {
			wfDebugLog( "Pathway",  "Unable to convert to svg, so cannot saveSvgCache." );
			return;
		}
		$file = $this->getFileLocation( FILETYPE_IMG, false );
		self::writeFile( $file, $svg );
		if ( !file_exists( $file ) ) {
			throw new Exception( "Unable to save svg" );
		}
		wfDebugLog( "Pathway",  "SVG CACHE SAVED: $file\n" );
	}

	private static function writeFile( $filename, $data ) {
		$dir = dirname( $filename );
		if ( !file_exists( $dir ) ) {
			wfDebugLog( "Pathway",  "Making $dir for $filename.\n" );
			if ( !wfMkdirParents( $dir ) ) {
				throw new Exception( "Couldn't make directory for pathway!" );
			}
		}
		$handle = fopen( $filename, 'w' );
		if ( !$handle ) {
			throw new Exception( "Couldn't open file $filename" );
		}
		if ( fwrite( $handle, $data ) === false ) {
			throw new Exception( "Couldn't write file $filename" );
		}
		if ( fclose( $handle ) === false ) {
			throw new Exception( "Couln't close file $filename" );
		}
	}

	private function savePngCache() {
		// NOTE: Inkscape has an open issue for not supporting
		// the CSS property dominant-baseline.
		// https://bugs.launchpad.net/inkscape/+bug/811862
		wfDebugLog( "Pathway",  "savePngCache() called\n" );
		global $wgSVGConverters, $wgSVGConverter, $wgSVGConverterPath;

		$input = $this->getFileLocation( FILETYPE_IMG );
		$output = $this->getFileLocation( FILETYPE_PNG, false );

		$width = 1000;
		$retval = 0;
		if ( isset( $wgSVGConverters[$wgSVGConverter] ) ) {
			// TODO: calculate proper height for rsvg
			$cmd = str_replace(
				[ '$path/', '$width', '$input', '$output' ],
				[
					$wgSVGConverterPath
					? wfEscapeShellArg( "$wgSVGConverterPath/" )
					: "",
					intval( $width ),
					wfEscapeShellArg( $input ),
					wfEscapeShellArg( $output )
				],
				$wgSVGConverters[$wgSVGConverter] ) . " 2>&1";
			$err = wfShellExec( $cmd, $retval );
			if ( $retval != 0 || !file_exists( $output ) ) {
				throw new Exception(
					"Unable to convert to png: $err\nCommand: $cmd"
				);

			}
		} else {
			throw new Exception(
				"Unable to convert to png, no SVG rasterizer found"
			);
		}
		wfDebugLog( "Pathway",  "PNG CACHE SAVED: $output\n" );
	}

	/**
	 * Toggle links. DRY.
	 *
	 * @param string $elId element ID that should be toggled.
	 * @param int $total available
	 * @param int $number to show
	 * @return string
	 */
	public static function toggleElement( $elId, $total, $number ) {
		$ret = "";
		if ( $total > $number ) {
			$ret = Html::rawElement(
				'div', null, Html::element( 'b', [
					'class' => 'toggleLink',
					'data-target' => $elId,
					'data-expand' => 'View all...',
					'data-collapse' => "View last $number"
				], 'View all...' ) );
		}
		return $ret;
	}
}
