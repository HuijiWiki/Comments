<?php

/**
 * Class for Comments methods that are not specific to one comments,
 * but specific to one comment-using page
 */
class CommentsPage extends ContextSource {

	/**
	 * @var Integer: page ID (page.page_id) of this page.
	 */
	public $id = 0;

	/**
	 * @var Integer: if this is _not_ 0, then the comments are ordered by their
	 *			   Comment_Score in descending order
	 */
	public $orderBy = 0;

	/**
	 * @var Integer: maximum amount of threads of comments shown per page before pagination is enabled;
	 */
	public $limit = 10;

	/**
	 * @TODO document
	 *
	 * @var int
	 */
	public $pagerLimit = 9;

	/**
	 * The current page of comments we are paged to
	 *
	 * @var int
	 */
	public $currentPagerPage = 0;

	/**
	 * List of users allowed to comment. Empty string - any user can comment
	 *
	 * @var string
	 */
	public $allow = '';

	/**
	 * What voting to disallow - disallow PLUS, MINUS, or BOTH
	 *
	 * @var string
	 */
	public $voting = '';

	/**
	 * @var Boolean: allow positive (plus) votes?
	 */
	public $allowPlus = true;

	/**
	 * @var Boolean: allow negative (minus) votes?
	 */
	public $allowMinus = true;

	/**
	 * @TODO document
	 *
	 * @var string
	 */
	public $pageQuery = 'cpage';

	/**
	 * @var Title: title object for this page
	 */
	public $title = null;

	/**
	 * List of lists of comments on this page.
	 * Each list is a separate 'thread' of comments, with the parent comment first, and any replies following
	 * Not populated until display() is called
	 *
	 * @var array
	 */
	public $comments = array();

	/**
	 * Constructor
	 *
	 * @param $pageID: current page ID
	 */
	function __construct ( $pageID, $context ) {
		$this->id = $pageID;
		$this->setContext( $context );
		$this->title = Title::newFromID( $pageID );
	}

	/**
	 * Gets the total amount of comments on this page
	 *
	 * @return int
	 */
	function countTotal() {
		$dbr = wfGetDB( DB_SLAVE );
		$count = 0;
		$s = $dbr->selectRow(
			'Comments',
			array( 'COUNT(*) AS CommentCount' ),
			array( 'Comment_Page_ID' => $this->id ),
			__METHOD__
		);
		if ( $s !== false ) {
			$count = $s->CommentCount;
		}
		return $count;
	}

	/**
	 * Gets the ID number of the latest comment for the current page.
	 *
	 * @return int
	 */
	function getLatestCommentID() {
		$latestCommentID = 0;
		$dbr = wfGetDB( DB_SLAVE );
		$s = $dbr->selectRow(
			'Comments',
			array( 'CommentID' ),
			array( 'Comment_Page_ID' => $this->id ),
			__METHOD__,
			array( 'ORDER BY' => 'Comment_Date DESC', 'LIMIT' => 1 )
		);
		if ( $s !== false ) {
			$latestCommentID = $s->CommentID;
		}
		return $latestCommentID;
	}

	/**
	 * Set voting either totally off, or disallow "thumbs down" or disallow
	 * "thumbs up".
	 *
	 * @param string $voting 'OFF', 'PLUS' or 'MINUS' (will be strtoupper()ed)
	 */
	function setVoting( $voting ) {
		$this->voting = $voting;
		$voting = strtoupper( $voting );

		if ( $voting == 'OFF' ) {
			$this->allowMinus = false;
			$this->allowPlus = false;
		}
		if ( $voting == 'PLUS' ) {
			$this->allowMinus = false;
		}
		if ( $voting == 'MINUS' ) {
			$this->allowPlus = false;
		}
	}

	/**
	 * Fetches all comments, called by display().
	 *
	 * @return array Array containing every possible bit of information about
	 *				a comment, including score, timestamp and more
	 */
	public function getComments( $limit=null ) {
		$dbr = wfGetDB( DB_SLAVE );
		$tables = array();
		$params = array();
		if(is_null($limit)){
			$params = array();
		}else{					
			$params = array('LIMIT' => $limit);
		}
		$joinConds = array();

		// Defaults (for non-social wikis)
		$tables[] = 'Comments';
		$fields = array(
			'Comment_Username', 'Comment_IP', 'Comment_Text',
			'Comment_Date', 'UNIX_TIMESTAMP(Comment_Date) AS timestamp',
			'Comment_user_id', 'CommentID', 'Comment_Parent_ID',
		);

		// If SocialProfile is installed, query the user_stats table too.
		if (
			$dbr->tableExists( 'user_stats' ) &&
			class_exists( 'UserProfile' )
		) {
			$tables[] = 'user_stats';
			$fields[] = 'stats_total_points';
			$joinConds = array(
				'Comments' => array(
					'LEFT JOIN', 'Comment_user_id = stats_user_id'
				)
			);
		}

		// Perform the query
		$res = $dbr->select(
			$tables,
			$fields,
			array( 'Comment_Page_ID' => $this->id ),
			__METHOD__,
			$params,
			$joinConds
		);

		$comments = array();

		foreach ( $res as $row ) {
			if ( $row->Comment_Parent_ID == 0 ) {
				$thread = $row->CommentID;
			} else {
				$thread = $row->Comment_Parent_ID;
			}
			$data = array(
				'Comment_Username' => $row->Comment_Username,
				'Comment_IP' => $row->Comment_IP,
				'Comment_Text' => $row->Comment_Text,
				'Comment_Date' => $row->Comment_Date,
				'Comment_user_id' => $row->Comment_user_id,
				'Comment_user_points' => ( isset( $row->stats_total_points ) ? number_format( $row->stats_total_points ) : 0 ),
				'CommentID' => $row->CommentID,
				'Comment_Parent_ID' => $row->Comment_Parent_ID,
				'thread' => $thread,
				'timestamp' => $row->timestamp
			);

			$comments[] = new Comment( $this, $this->getContext(), $data );
		}

		$commentThreads = array();

		foreach ( $comments as $comment ) {
			if ( $comment->parentID == 0 ) {
				$commentThreads[$comment->id] = array( $comment );
			} else {
				$commentThreads[$comment->parentID][] = $comment;
			}
		}

		return $commentThreads;
	}

	/**
	 * @return int The page we are currently paged to
	 * not used for any API calls
	 */
	function getCurrentPagerPage() {
		if ( $this->currentPagerPage == 0 ) {
			$this->currentPagerPage = $this->getRequest()->getInt( $this->pageQuery, 1 );

			if ( $this->currentPagerPage < 1 ) {
				$this->currentPagerPage = 1;
			}
		}

		return $this->currentPagerPage;
	}

	/**
	 * Display pager for the current page.
	 *
	 * @param int $pagerCurrent Page we are currently paged to
	 * @param int $pagesCount The maximum page number
	 *
	 * @return string: the links for paging through pages of comments
	 */
	function displayPager( $pagerCurrent, $pagesCount ) {
		// Middle is used to "center" pages around the current page.
		$pager_middle = ceil( $this->pagerLimit / 2 );
		// first is the first page listed by this pager piece (re quantity)
		$pagerFirst = $pagerCurrent - $pager_middle + 1;
		// last is the last page listed by this pager piece (re quantity)
		$pagerLast = $pagerCurrent + $this->pagerLimit - $pager_middle;

		// Prepare for generation loop.
		$i = $pagerFirst;
		if ( $pagerLast > $pagesCount ) {
			// Adjust "center" if at end of query.
			$i = $i + ( $pagesCount - $pagerLast );
			$pagerLast = $pagesCount;
		}
		if ( $i <= 0 ) {
			// Adjust "center" if at start of query.
			$pagerLast = $pagerLast + ( 1 - $i );
			$i = 1;
		}

		$output = '';
		if ( $pagesCount > 1 ) {
			$output .= '<div class="hj-media-pager-wrapper"><ul class="hj-media-pager pagination">';
			$pagerEllipsis = '<li class="hj-media-pager-item hj-media-pager-ellipsis"><span>...</span></li>';

			// Whether to display the "Previous page" link
			if ( $pagerCurrent > 1 ) {
				$output .= '<li class="hj-media-pager-item hj-media-pager-previous">' .
					Html::rawElement(
						'a',
						array(
							'rel' => 'nofollow',
							'class' => 'hj-media-pager-link',
							'href' => '#cfirst',
							'aria-label' => 'Previous',
							'data-' . $this->pageQuery => ( $pagerCurrent - 1 ),
						),
						'<span aria-hidden="true">&laquo;</span>'
					) .
					'</li>';
			}

			// Whether to display the "First page" link
			if ( $i > 1 ) {
				$output .= '<li class="hj-media-pager-item hj-media-pager-first">' .
					Html::rawElement(
						'a',
						array(
							'rel' => 'nofollow',
							'class' => 'hj-media-pager-link',
							'href' => '#cfirst',
							'data-' . $this->pageQuery => 1,
						),
						1
					) .
					'</li>';
			}

			// When there is more than one page, create the pager list.
			if ( $i != $pagesCount ) {
				if ( $i > 2 ) {
					$output .= $pagerEllipsis;
				}

				// Now generate the actual pager piece.
				for ( ; $i <= $pagerLast && $i <= $pagesCount; $i++ ) {
					if ( $i == $pagerCurrent ) {
						$output .= '<li class="hj-media-pager-item hj-media-pager-current active"><span>' .
							$i . '</span></li>';
					} else {
						$output .= '<li class="hj-media-pager-item">' .
							Html::rawElement(
								'a',
								array(
									'rel' => 'nofollow',
									'class' => 'hj-media-pager-link',
									'href' => '#cfirst',
									'data-' . $this->pageQuery => $i,
								),
								$i
							) .
							'</li>';
					}
				}

				if ( $i < $pagesCount ) {
					$output .= $pagerEllipsis;
				}
			}

			// Whether to display the "Last page" link
			if ( $pagesCount > ( $i - 1 ) ) {
				$output .= '<li class="hj-media-pager-item hj-media-pager-last">' .
					Html::rawElement(
						'a',
						array(
							'rel' => 'nofollow',
							'class' => 'hj-media-pager-link',
							'href' => '#cfirst',
							'data-' . $this->pageQuery => $pagesCount,
						),
						$pagesCount
					) .
					'</li>';
			}

			// Whether to display the "Next page" link
			if ( $pagerCurrent < $pagesCount ) {
				$output .= '<li class="hj-media-pager-item hj-media-pager-next">' .
					Html::rawElement(
						'a',
						array(
							'rel' => 'nofollow',
							'class' => 'hj-media-pager-link',
							'href' => '#cfirst',
							'aria-label' => 'Next',
							'data-' . $this->pageQuery => ( $pagerCurrent + 1 ),
						),
						'<span aria-hidden="true">&raquo;</span>'
					) .
					'</li>';
			}

			$output .= '</ul></div>';
			$output .= $pager;
		}

		return $output;
	}

	/**
	 * Get this list of anon commenters in the given list of comments,
	 * and return a mapped array of IP adressess to the the number anon poster
	 * (so anon posters can be called Anon#1, Anon#2, etc
	 *
	 * @return array
	 */
	function getAnonList() {
		$counter = 1;
		$bucket = array();

		$commentThreads = $this->comments;

		$comments = array(); // convert 2nd threads array to a simple list of comments
		foreach ( $commentThreads as $thread ) {
			$comments = array_merge( $comments, $thread );
		}
		usort( $comments, array( 'CommentFunctions', 'sortTime' ) );

		foreach ( $comments as $comment ) {
			if (
				!array_key_exists( $comment->username, $bucket ) &&
				$comment->userID == 0
			) {
				$bucket[$comment->username] = $counter;
				$counter++;
			}
		}

		return $bucket;
	}

	/**
	 * Sort an array of comment threads
	 * @param $threads
	 * @return mixed
	 */
	function sort( $threads ) {
		global $wgCommentsSortDescending;

		if ( $this->orderBy ) {
			usort( $threads, array( 'CommentFunctions', 'sortScore' ) );
		} elseif ( $wgCommentsSortDescending ) {
			usort( $threads, array( 'CommentFunctions', 'sortDesc' ) );
		} else {
			usort( $threads, array( 'CommentFunctions', 'sortAsc' ) );
		}

		return $threads;
	}

	/**
	 * Convert an array of comment threads into an array of pages (arrays) of comment threads
	 * @param $comments
	 * @return array
	 */
	function page( $comments ) {
		return array_chunk( $comments, $this->limit );
	}


	/**
	 * Display all the comments for the current page.
	 * CSS and JS is loaded in Comment.php
	 */
	function display( ) {
		global $wgMemc;

		$output = '';

		// Try cache
		$key = wfMemcKey( 'comment', 'pagethreadlist', $this->id );
		$data = $wgMemc->get( $key );

		if ( $data && is_object($data) ){
			$commentThreads = $data;			
		} else {
			$commentThreads = $this->getComments();
			try{
				$wgMemc->set( $key, $commentThreads );
			} catch (Exception $e){
				wfDebug($e);
			}			
		}

		$commentThreads = $this->sort( $commentThreads );
		$this->comments = $commentThreads;

		$commentPages = $this->page( $commentThreads );
		$currentPageNum = $this->getCurrentPagerPage();
		$numPages = count( $commentPages );
		$currentPage = array();
		if ( $numPages > 0 ) {
			$currentPage = $commentPages[$currentPageNum - 1];
		}

		// Load complete blocked list for logged in user so they don't see their comments
		$blockList = array();
		// if ( $this->getUser()->getID() != 0 ) {
		// 	$blockList = CommentFunctions::getBlockList( $this->getUser()->getId() );
		// }

		if ( count($currentPage) > 0 ) {
			$pager = $this->displayPager( $currentPageNum, $numPages );
			// $output .= $pager;
			$output .= '<a id="cfirst" name="cfirst" rel="nofollow"></a>';

			// $anonList = $this->getAnonList();

			foreach ( $currentPage as $id => $thread ) {
				$parent = $thread[0];
				unset($thread[0]);
				$output .= $parent->showComment(false, 'full', '', '', $thread);
			}
			$output .= $pager;
		}

		return $output;
	}

	/**
	 * Displays the "Sort by X" form and a link to auto-refresh comments
	 *
	 * @return string HTML
	 */
	function displayOrderForm() {
		$output = '<div class="c-order btn-group btn-group-justified hidden">
			<div class="c-order-select">
				<form name="ChangeOrder" action="">
					<select name="TheOrder" class="btn btn-default dropdown-toggle">
						<option value="0">' .
			wfMessage( 'comments-sort-by-date' )->plain() .
			'</option>
						<option value="1">' .
			wfMessage( 'comments-sort-by-score' )->plain() .
			'</option>
					</select>
				</form>
			</div>
			<div id="spy" class="c-spy">
				<a class="btn btn-default" href="javascript:void(0)">' .
			wfMessage( 'comments-auto-refresher-enable' )->plain() .
			'</a>
			</div>
			<div class="cleared"></div>
		</div>';
		return $output;
	}

	/**
	 * Displays the form for adding new comments
	 *
	 * @return string HTML output
	 */
	function displayForm() {
		$output = '<section class="container-fluid"><form name="commentForm"><div id="replyto" class="c-form-reply-to"></div><div class="row comment-text-wrapper">' . "\n";
		// $output = '<div name="commentForm">' . "\n";

		if ( $this->allow ) {
			$pos = strpos(
				strtoupper( addslashes( $this->allow ) ),
				strtoupper( addslashes( $this->getUser()->getName() ) )
			);
		}

		// 'comment' user right is required to add new comments
		// if ( !$this->getUser()->isAllowed( 'comment' ) ) {
		// 	$output .= wfMessage( 'comments-not-allowed' )->parse();
		// } else {
			// Blocked users can't add new comments under any conditions...
			// and maybe there's a list of users who should be allowed to post
			// comments
			if ( $this->getUser()->isBlocked() == false && ( $this->allow == '' || $pos !== false ) ) {
				if ($this->getUser()->isLoggedIn() ){
					$avatar = new wAvatar($this->getUser()->getId(), 'ml');
					$avatarAnchor = $avatar->getAvatarAnchor();
					$output .= '<div class="c-form-title"><div class="hj-media-avatar">'.$avatarAnchor.'</div></div>' . "\n";
										
				}
				// Show a message to anons, prompting them to register or log in
				else {
					$register_title = SpecialPage::getTitleFor( 'Userlogin', 'signup' );
					$output .= '<div class="c-form-message alert alert-warning" role="alert">' . wfMessage(
							'comments-anon-message',
							htmlspecialchars( $register_title->getFullURL() )
						)->text() . '<a id="TcLogin">登录</a>。&nbsp;&nbsp;
                            <a href="javascript:void(0)" class="icon-weibo-share"></a>&nbsp;&nbsp;
                            <a href="https://graph.qq.com/oauth2.0/authorize?response_type=code&amp;client_id=101264508&amp;state=huijistate&amp;redirect_uri=http%3a%2f%2fwww.huiji.wiki%2fwiki%2fspecial%3acallbackqq" class="icon-qq-share"></a>
                        </div>' . "\n";
					$avatar = new wAvatar( 0, 'ml');
					$avatarAnchor = $avatar->getAvatarAnchor();
					$output .= '<div class="c-form-title"><div class="hj-media-avatar">'.$avatarAnchor.'</div></div>' . "\n";
				}
				$rand = rand(1, 9);
				$placeholder = wfMessage('comments-placeholder-'.$rand)->parse();
				$output .= '<div class="lead emoji-picker-container hj-media-body"><textarea name="commentText" id="comment" placeholder="'.$placeholder.'" class="mw-ui-input text-area mention-area" rows="5" cols="64" data-emojiable="true" data-emoji-input="unicode"></textarea></div></div>' . "\n";

				$output .= '<div class="comment-list clear"><div class="mw-ui-button mw-ui-primary site-button pull-right" id="tc_comment" >发表</div>'. "\n";

				if (wfMessage('comments-add-emoji-emote')->parse() != ''){
					$output .= '<div class="mw-ui-button mw-ui-primary site-button pull-right" id="custom_comment">模板</div>' . "\n";
					$output .= '<span class="custom-face">'.wfMessage('comments-add-emoji-emote')->parse().'</span></div>';					
				}

			}
			$output .= '<input type="hidden" name="action" value="purge" />' . "\n";
			$output .= '<input type="hidden" name="pageId" value="' . $this->id . '" />' . "\n";
			$output .= '<input type="hidden" name="commentid" />' . "\n";
			$output .= '<input type="hidden" name="lastCommentId" value="' . $this->getLatestCommentID() . '" />' . "\n";
			$output .= '<input type="hidden" name="commentParentId" />' . "\n";
			$output .= '<input type="hidden" name="' . $this->pageQuery . '" value="' . $this->getCurrentPagerPage() . '" />' . "\n";
			$output .= Html::hidden( 'token', $this->getUser()->getEditToken() );
		// }
		$output .= '</form></section>' . "\n";
		return $output;
	}

	/**
	 * Purge caches (memcached, parser cache and Squid cache)
	 * Edited by Reasno: we will hold on parser cache and squid cache as it is not required to be purged.
	 */
	function clearCommentListCache() {

		if ($this->title == null){
			return;
		}
		$key = wfMemcKey( 'comment', 'pagethreadlist', $this->id );
		$jobParams = array( 'key' => $key );
		$job = new InvalidatePageCacheJob( $this->title, $jobParams );
		JobQueueGroup::singleton()->push( $job );
	
	}

	/**
	 * get hot comment by vote
	 */
	function getHotComments( ){
		global $wgCommentsDefaultAvatar, $wgUserLevels, $wgUser;

		$templateParser = new TemplateParser(  __DIR__ . '/View' );
		$allComments = $this->getComments();
		$output = '';
		uasort($allComments, function($a, $b){
			if ( $a[0]->currentScore == $b[0]->currentScore )
				return 0;
			return ($a[0]->currentScore > $b[0]->currentScore)? -1 : 1;

		});
		if( count($allComments) > 10 ){
			$i = 0;
			$displayed = false;
			$output .='<div class="hot-comments">';
			foreach ($allComments as $key => $value) {
				if ( $i == 3 ) {
					break;
				}
				if ($value[0]->ip == 0){
					continue; //ensure this comment is not deleted
				}
				$output .= $value[0]->showComment(false, 'full');
				$displayed = true;
				$i++;
		        	
			}
			if ( $displayed ) {
				$output .= '</div><fieldset><legend>以上为热门吐槽,<a class="comment-more">查看更多热门</a></legend></fieldset>';
			}else {
				$output .= "</div>";
			}
		}
		return $output;

	}

}
