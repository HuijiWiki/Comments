<?php
/**
 * First we clear memcached  object cache. 
 * Second we clear page cache
 * third we clear squid cache
 * TODO: clear CDN cache as an option.
 *
 */
class InvalidatePageCacheJob extends Job {
	public function __construct( $title, $params ) {
		// Replace synchroniseThreadArticleData with an identifier for your job.
		parent::__construct( 'invalidatePageCacheJob', $title, $params );
	}

	/**
	 * Execute the job
	 *
	 * @return bool
	 */
	public function run() {
		global $wgMemc;
		// Load data from $this->params and $this->title
		$article = new WikiPage( $this->title );
		$key = $this->params['key'];
		
		wfDebug( "Clearing cache of page {$this->title} from cache\n" );
		$wgMemc->delete( $key );

		if ( is_object( $this->title ) ) {
		 	$this->title->invalidateCache();
		 	$this->title->purgeSquid();
		}
		return true;
	}
}
?>