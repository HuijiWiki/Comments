<?php
/**
 * Comment class
 * Functions for managing comments and everything related to them, including:
 * -blocking comments from a given user
 * -counting the total amount of comments in the database
 * -displaying the form for adding a new comment
 * -getting all comments for a given page
 *
 * @file
 * @ingroup Extensions
 */
class Comment extends ContextSource {
	/**
	 * @var CommentsPage: page of the page the <comments /> tag is in
	 */
	public $page = null;

	/**
	 * @var Integer: total amount of comments by distinct commenters that the
	 *               current page has
	 */
	public $commentTotal = 0;

	/**
	 * @var String: text of the current comment
	 */
	public $text = null;

	/**
	 * Date when the comment was posted
	 *
	 * @var null
	 */
	public $date = null;

	/**
	 * @var Integer: internal ID number (Comments.CommentID DB field) of the
	 *               current comment that we're dealing with
	 */
	public $id = 0;

	/**
	 * @var Integer: ID of the parent comment, if this is a child comment
	 */
	public $parentID = 0;

	/**
	 * The current vote from this user on this comment
	 *
	 * @var int|boolean: false if no vote, otherwise -1, 0, or 1
	 */
	public $currentVote = false;

	/**
	 * @var string: comment score (SUM() of all votes) of the current comment
	 */
	public $currentScore = '0';

	/**
	 * Username of the user who posted the comment
	 *
	 * @var string
	 */
	public $username = '';

	/**
	 * IP of the comment poster
	 *
	 * @var string
	 */
	public $ip = '';

	/**
	 * ID of the user who posted the comment
	 *
	 * @var int
	 */
	public $userID = 0;

	/**
	 * @TODO document
	 *
	 * @var int
	 */
	public $userPoints = 0;

	/**
	 * Comment ID of the thread this comment is in
	 * this is the ID of the parent comment if there is one,
	 * or this comment if there is not
	 * Used for sorting
	 *
	 * @var null
	 */
	public $thread = null;

	/**
	 * Unix timestamp when the comment was posted
	 * Used for sorting
	 * Processed from $date
	 *
	 * @var null
	 */
	public $timestamp = null;

	/**
	 * Constructor - set the page ID
	 *
	 * @param $page CommentsPage: ID number of the current page
	 * @param IContextSource $context
	 * @param $data: straight from the DB about the comment
	 */
	public function __construct( CommentsPage $page, $context = null, $data ) {
		$this->page = $page;

		$this->setContext( $context );

		$this->username = $data['Comment_Username'];
		$this->ip = $data['Comment_IP'];
		$this->text = $data['Comment_Text'];
		$this->date = $data['Comment_Date'];
		$this->userID = $data['Comment_user_id'];
		$this->userPoints = $data['Comment_user_points'];
		$this->id = $data['CommentID'];
		$this->parentID = $data['Comment_Parent_ID'];
		$this->thread = $data['thread'];
		$this->timestamp = $data['timestamp'];


		$dbr = wfGetDB( DB_SLAVE );
		$row = $dbr->selectRow(
			'Comments_Vote',
			array( 'Comment_Vote_Score' ),
			array(
				'Comment_Vote_ID' => $this->id,
				'Comment_Vote_Username' => $this->getUser()->getName()
			),
			__METHOD__
		);
		if ( $row !== false ) {
			$vote = $row->Comment_Vote_Score;
		} else {
			$vote = false;
		}

		$this->currentVote = $vote;

		$this->currentScore = $this->getScore();
	}

	public static function newFromID( $id ) {
		$context = RequestContext::getMain();
		$dbr = wfGetDB( DB_SLAVE );

		if ( !is_numeric( $id ) || $id == 0 ) {
			return null;
		}

		$tables = array();
		$params = array();
		$joinConds = array();

		// Defaults (for non-social wikis)
		$tables[] = 'Comments';
		$fields = array(
			'Comment_Username', 'Comment_IP', 'Comment_Text',
			'Comment_Date', 'UNIX_TIMESTAMP(Comment_Date) AS timestamp',
			'Comment_user_id', 'CommentID', 'Comment_Parent_ID',
			'CommentID', 'Comment_Page_ID'
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
			array( 'CommentID' => $id ),
			__METHOD__,
			$params,
			$joinConds
		);

		$row = $res->fetchObject();

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

		$page = new CommentsPage( $row->Comment_Page_ID, $context );

		return new Comment( $page, $context, $data );
	}

	/**
	 * Parse and return the text for this comment
	 *
	 * @return mixed|string
	 * @throws MWException
	 */
	function getText() {
		global $wgParser;

		$commentText = trim( str_replace( '&quot;', "'", $this->text ) );
		$comment_text_parts = explode( "\n", $commentText );
		$comment_text_fix = '';
		foreach ( $comment_text_parts as $part ) {
			$comment_text_fix .= ( ( $comment_text_fix ) ? "\n" : '' ) . trim( $part );
		}
		// fix link display error #bug
		$commentText = $this->getOutput()->parse( $comment_text_fix );		
		// if ( $this->getTitle()->getArticleID() > 0 ) {
		// 	// $commentText = $wgParser->recursiveTagParse( $comment_text_fix );
		// } else {
		// 	// $commentText = $this->getOutput()->parse( $comment_text_fix );
		// }
		// really bad hack because we want to parse=firstline, but don't want wrapping <p> tags
		if ( substr( $commentText, 0 , 3 ) == '<p>' ) {
			$commentText = substr( $commentText, 3 );
		}

		if ( substr( $commentText, strlen( $commentText ) -4 , 4 ) == '</p>' ) {
			$commentText = substr( $commentText, 0, strlen( $commentText ) -4 );
		}

		// make sure link text is not too long (will overflow)
		// this function changes too long links to <a href=#>http://www.abc....xyz.html</a>
		$commentText = preg_replace_callback(
			"/(<a[^>]*>)(.*?)(<\/a>)/i",
			array( 'CommentFunctions', 'cutCommentLinkText' ),
			$commentText
		);

		return $commentText;
	}


	/**
	 * Adds the comment and all necessary info into the Comments table in the
	 * database.
	 *
	 * @param string $text: text of the comment
	 * @param CommentsPage $page: container page
	 * @param User $user: user commenting
	 * @param int $parentID: ID of parent comment, if this is a reply
	 *
	 * @return Comment: the added comment
	 */
	static function add( $text, CommentsPage $page, User $user, $parentID ) {
		global $wgCommentsInRecentChanges;
		
		$text = CommentFunctions::preprocessText($text);
		$dbw = wfGetDB( DB_MASTER );
		$context = RequestContext::getMain();
		wfSuppressWarnings();
		$commentDate = date( 'Y-m-d H:i:s' );
		wfRestoreWarnings();
		$dbw->insert(
			'Comments',
			array(
				'Comment_Page_ID' => $page->id,
				'Comment_Username' => $user->getName(),
				'Comment_user_id' => $user->getId(),
				'Comment_Text' => $text,
				'Comment_Date' => $commentDate,
				'Comment_Parent_ID' => $parentID,
				'Comment_IP' => HuijiFunctions::getIp()
			),
			__METHOD__
		);
		$commentId = $dbw->insertId();
		$dbw->commit(); // misza: added this
		$id = $commentId;

		$page->clearCommentListCache();

		// Add a log entry.
		$pageTitle = Title::newFromID( $page->id );
		if ($pageTitle != null){
			$logEntry = new ManualLogEntry( 'comments', 'add' );
			$logEntry->setPerformer( $user );
			$logEntry->setTarget( $pageTitle );
			$logEntry->setComment( $text );
			$logEntry->setParameters( array(
				'4::commentid' => $commentId
			) );
			$logId = $logEntry->insert();
			$logEntry->publish( $logId, ( $wgCommentsInRecentChanges ? 'rcandudp' : 'udp' ) );			
		}

		$dbr = wfGetDB( DB_SLAVE );
		if (
			$dbr->tableExists( 'user_stats' ) &&
			class_exists( 'UserProfile' )
		) {
			$res = $dbr->select( // need this data for seeding a Comment object
				'user_stats',
				'stats_total_points',
				array( 'stats_user_id' => $user->getId() ),
				__METHOD__
			);

			$row = $res->fetchObject();
			$userPoints = number_format( $row->stats_total_points );
		} else {
			$userPoints = 0;
		}
		if ( $parentID == 0 ) {
			$thread = $id;
		} else {
			$thread = $parentID;
		}
		$data = array(
			'Comment_Username' => $user->getName(),
			'Comment_IP' => $context->getRequest()->getIP(),
			'Comment_Text' => $text,
			'Comment_Date' => $commentDate,
			'Comment_user_id' => $user->getID(),
			'Comment_user_points' => $userPoints,
			'CommentID' => $id,
			'Comment_Parent_ID' => $parentID,
			'thread' => $thread,
			'timestamp' => strtotime( $commentDate )
		);
		$page = new CommentsPage( $page->id, $context );
		$comment = new Comment( $page, $context, $data );
		wfRunHooks( 'Comment::add', array( $comment, $commentId, $comment->page->id ) );
		if ($parentID !== 0) {
			$comment->sendEchoNotification( 'reply', $comment->id );
		}
		$mentionedUsers = CommentFunctions::getMentionedUsers( $text );
		if ( count( $mentionedUsers ) ) {
			$comment->sendEchoNotification('mention', $comment->id, $mentionedUsers);
		}
		return $comment;
	}

	/**
	 * Gets the score for this comment from the database table Comments_Vote
	 *
	 * @return string
	 */
	function getScore() {
		$dbr = wfGetDB( DB_SLAVE );
		$row = $dbr->selectRow(
			'Comments_Vote',
			array( 'SUM(Comment_Vote_Score) AS CommentScore' ),
			array( 'Comment_Vote_ID' => $this->id ),
			__METHOD__
		);
		$score = '0';
		if ( $row !== false && $row->CommentScore ) {
			$score = $row->CommentScore;
		}
		return $score;
	}

	/**
	 * Adds a vote for a comment if the user hasn't voted for said comment yet.
	 *
	 * @param $value int: upvote or downvote (1 or -1)
	 */
	function vote( $value ) {
		global $wgMemc;
		$dbw = wfGetDB( DB_MASTER );

		if ( $value < 0 ) { // limit to range -1 -> 0 -> 1
			$value = -1;
		} elseif ( $value > 0 ) {
			$value = 1;
			$this->sendEchoNotification('plus', $this->id);
		}

		if ( $value == $this->currentVote ) { // user toggling off a preexisting vote
			$value = 0;
		}

		wfSuppressWarnings();
		$commentDate = date( 'Y-m-d H:i:s' );
		wfRestoreWarnings();

		if ( $this->currentVote === false ) { // no vote, insert
			$dbw->insert(
				'Comments_Vote',
				array(
					'Comment_Vote_id' => $this->id,
					'Comment_Vote_Username' => $this->getUser()->getName(),
					'Comment_Vote_user_id' => $this->getUser()->getId(),
					'Comment_Vote_Score' => $value,
					'Comment_Vote_Date' => $commentDate,
					'Comment_Vote_IP' => HuijiFunctions::getIp()
				),
				__METHOD__
			);
		} else { // already a vote, update
			$dbw->update(
				'Comments_Vote',
				array(
					'Comment_Vote_Score' => $value,
					'Comment_Vote_Date' => $commentDate,
					'Comment_Vote_IP' => HuijiFunctions::getIp()
				),
				array(
					'Comment_Vote_id' => $this->id,
					'Comment_Vote_Username' => $this->getUser()->getName(),
					'Comment_Vote_user_id' => $this->getUser()->getId(),
				),
				__METHOD__
			);
		}
		$dbw->commit();
		$this->page->clearCommentListCache();

		// update cache for comment list
		// should perform better than deleting cache completely since Votes happen more frequently
		$key = wfMemcKey( 'comment', 'pagethreadlist', $this->page->id );
		$comments = $wgMemc->get( $key );
		if ( is_object($comments) ) {
			foreach ( $comments as &$comment ) {
				if ( $comment->id == $this->id ) {
					$comment->currentScore = $this->currentScore;
				}
			}
			$wgMemc->set( $key, $comments );
		}

		$score = $this->getScore();

		$this->currentVote = $value;
		$this->currentScore = $score;
	}

	/**
	 * Deletes entries from Comments and Comments_Vote tables and clears caches
	 */
	function delete() {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update(
			'Comments',
			array('Comment_IP' => 0),
			array( 'CommentID' => $this->id ),
			__METHOD__
		);
		$dbw->delete(
			'Comments_Vote',
			array( 'Comment_Vote_ID' => $this->id ),
			__METHOD__
		);
		$dbw->commit();

		// Log the deletion to Special:Log/comments.
		global $wgCommentsInRecentChanges;
		$logEntry = new ManualLogEntry( 'comments', 'delete' );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( Title::newFromId( $this->page->id ) );
		$logEntry->setParameters( array(
			'4::commentid' => $this->id
		) );
		$logId = $logEntry->insert();
		$logEntry->publish( $logId, ( $wgCommentsInRecentChanges ? 'rcandudp' : 'udp' ) );

		// Clear memcache & Squid cache
		$this->page->clearCommentListCache();

		// Ping other extensions that may have hooked into this point (i.e. LinkFilter)
		wfRunHooks( 'Comment::delete', array( $this, $this->id, $this->page->id ) );
	}

	/**
	 * Return the HTML for the comment vote links
	 *
	 * @param int $voteType up (+1) vote or down (-1) vote
	 * @return string
	 */
	function getVoteLink( $voteType ) {
		global $wgExtensionAssetsPath;

		// Blocked users cannot vote, obviously
		if ( $this->getUser()->isBlocked() ) {
			return '';
		}
		if ( !$this->getUser()->isAllowed( 'comment' ) ) {
			return '';
		}

		$voteLink = '';
		if ( $this->getUser()->isLoggedIn() ) {
			$voteLink .= '<a id="comment-vote-link" data-comment-id="' .
				$this->id . '" data-vote-type="' . $voteType .
				'" data-voting="' . $this->page->voting . '" href="javascript:void(0);">';
		} else {
			$login = SpecialPage::getTitleFor( 'Userlogin' ); // Anonymous users need to log in before they can vote
			$returnTo = $this->page->title->getPrefixedDBkey(); // Determine a sane returnto URL parameter

			$voteLink .=
				"<a href=\"" .
				htmlspecialchars( $login->getLocalURL( array( 'returnto' => $returnTo ) ) ) .
				"\" rel=\"nofollow\">";
		}

		if ( $voteType == 1 ) {
			if ( $this->currentVote == 1 ) {
				$voteLink .= "<i class=\"icon-like active\"></i> </a>";
			} else {
				$voteLink .= "<i class=\"icon-like\"></i> </a>";
			}
		} else {
			if ( $this->currentVote == -1 ) {
				$voteLink .= "<i class=\"icon-dislike active\"></i></a>";
			} else {
				$voteLink .= "<i class=\"icon-dislike\"></i></a>";
			}
		}

		return $voteLink;
	}

	// /**
	//  * Show the HTML for this comment and ignore section
	//  *
	//  * @param array $blockList list of users the current user has blocked
	//  * @param array $anonList map of ip addresses to names like anon#1, anon#2
	//  * @return string html
	//  */
	// function display( $blockList, $anonList ) {
		
	// 	if ( $this->parentID == 0 ) {
	// 		$container_class = 'full';
	// 	} else {
	// 		$container_class = 'reply';
	// 		// $this->sendEchoNotification( 'reply',$this->id );
	// 	}

	// 	$output = '';

	// 	// if ( in_array( $this->username, $blockList ) ) {
	// 	// 	$output .= $this->showIgnore( false, $container_class );
	// 	// 	$output .= $this->showComment( true, $container_class, $blockList, $anonList );
	// 	// } else {
	// 		// $output .= $this->showIgnore( true, $container_class );
	// 	$output .= $this->showComment( false, $container_class, $blockList, $anonList );
	// 	// }

	// 	return $output;
	// }

	function displayForCommentOfTheDay() {
		// global $wgHuijiPrefix;
		$output = '';

		$title2 = $this->page->getTitle();

		if ( $this->userID != 0 ) {
			$title = Title::makeTitle( NS_USER, $this->username );
			$commentPoster_Display = $this->username;
			$commentPoster = '<a href="' . $title->getFullURL() . '" title="' . $title->getText() . '" rel="nofollow">' . $this->username . '</a>';
			if ( class_exists( 'wAvatar' ) ) {
				$avatar = new wAvatar( $this->userID, 'm' );
				$commentIcon = $avatar->getAvatarAnchor();
			} else {
				$commentIcon = '';
			}
		} else {
			$commentPoster_Display = wfMessage( 'comments-anon-name' )->plain();
			$commentPoster = wfMessage( 'comments-anon-name' )->plain();
			$commentIcon = 'default_s.gif';
		}

		$avatarHTML = '';
		if ( class_exists( 'wAvatar' ) ) {
			global $wgUploadPath;
			$avatarHTML = $avatar->getAvatarAnchor();
		}

		$comment_text = mb_substr( $this->text, 0, 50, "utf-8" );
		if ( $comment_text != $this->text ) {
			$comment_text .= wfMessage( 'ellipsis' )->plain();
		}
		$output .= '<li class="cod">';

		$sign = '';
		if ( $this->currentScore > 0 ) {
			$sign = '+';
		} elseif ( $this->currentScore < 0 ) {
			$sign = '-'; // this *really* shouldn't be happening...
		}
		if ($this->page->title == null){
			return '';
		}
		$this->page->title->setFragment('#comment-' . $this->id);
		//@Since 1.27
		//$tf = $this->page->title->createFragmentTarget( '#comment-' . $this->id );
		$output .= '<span class="cod-score pull-right icon-star data-toggle="tooltip" data-placement="top" title="赞">' . $sign . $this->currentScore .
			'</span> ' . $avatarHTML .
			'<span class="cod-poster">' . $commentPoster . '</span>'.' @ <a class="mw-ui-anchor mw-ui-progressive mw-ui-quiet" href="'.$this->page->title->getLocalURL().
			'">'.$this->page->title.'</a><div class="c-sep"></div>';
		$output .= '<div><span class="cod-comment"><a class="mw-ui-anchor mw-ui-progressive mw-ui-quiet" href="' .$this->page->title->getFullURL();
		$output .='">' . $comment_text.'</a></span></div>';
		$output .= '</li>';
		return $output;
	}

	// /**
	//  * Show the box for if this comment has been ignored
	//  *
	//  * @param bool $hide
	//  * @param $containerClass
	//  * @return string
	//  */
	// function showIgnore( $hide = false, $containerClass ) {
	// 	$blockListTitle = SpecialPage::getTitleFor( 'CommentIgnoreList' );

	// 	$style = '';
	// 	if ( $hide ) {
	// 		$style = " style='display:none;'";
	// 	}

	// 	$output = "<div id='ignore-{$this->id}' class='c-ignored {$containerClass}'{$style}>\n";
	// 	$output .= wfMessage( 'comments-ignore-message' )->parse();
	// 	$output .= '<div class="c-ignored-links">' . "\n";
	// 	$output .= "<a href=\"javascript:void(0);\" data-comment-id=\"{$this->id}\">" .
	// 		$this->msg( 'comments-show-comment-link' )->plain() . '</a> | ';
	// 	$output .= '<a href="' . htmlspecialchars( $blockListTitle->getFullURL() ) . '">' .
	// 		$this->msg( 'comments-manage-blocklist-link' )->plain() . '</a>';
	// 	$output .= '</div>' . "\n";
	// 	$output .= '</div>' . "\n";

	// 	return $output;
	// }

	/**
	 * check comment is have replay
	 *
	 * @param int $commentId [commnet id ]
	 * @return  boolean [if isset replay return true, else return false]
	 */
	function hasReply(){
		$dbr = wfGetDB( DB_SLAVE );
		$row = $dbr->selectRow(
			'comments',
			array( 'CommentID' ),
			array( 'Comment_Parent_ID' => $this->id ),
			__METHOD__
		);
		if ( $row !== false && $row->CommentID ) {
			return true;
		}else{
			return false;
		}
	}

	/**
	 * Show the comment
	 *
	 * @param bool $hide: if true, comment is returned but hidden (display:none)
	 * @param $containerClass
	 * @param $blockList
	 * @param $anonList
	 * @param $children
	 * @return string
	 */
	function showComment( $hide = false, $containerClass, $blockList = null, $anonList = null, $children = array() ) {
		global $wgUserLevels, $wgExtensionAssetsPath;
		$templateParser = new TemplateParser(  __DIR__ . '/View' );
		$style = '';
		// if ( $hide ) {
		// 	$style = " style='display:none;'";
		// }

		$commentPosterLevel = '';

		if ( $this->userID != 0 ) {
			$title = Title::makeTitle( NS_USER, $this->username );

			$commentPoster = Linker::linkKnown($title, $this->username);

			$CommentReplyTo = $this->username;

			if ( $wgUserLevels && class_exists( 'UserLevel' ) ) {
				$user_level = new UserLevel( $this->userPoints );
				$commentPosterLevel = "{$user_level->getLevelName()}";
			}

			$user = User::newFromId( $this->userID );
			$CommentReplyToGender = $user->getOption( 'gender', 'unknown' );
		} else {
			$anonMsg = $this->msg( 'comments-anon-name' )->inContentLanguage()->plain();
			$commentPoster = $anonMsg . ' #' . $anonList[$this->username];
			$CommentReplyTo = $anonMsg;
			$CommentReplyToGender = 'unknown'; // Undisclosed gender as anon user
		}

		// Comment delete button for privileged users
		$dlt = '';

		

		if ( $this->parentID == 0 ) {
			$comment_class = 'f-message';
			$childrenCommentsHtml = '';
			foreach ($children as $child) {
				$childrenCommentsHtml .= $child->showComment(false, 'reply', null, null);
				# code...
			}
		} else {
			$comment_class = 'r-message';
			$childrenCommentsHtml = '';
		}

		// Display Block icon for logged in users for comments of users
		// that are already not in your block list
		$blockLink = '';

		// if (
		// 	$this->getUser()->getID() != 0 && $this->getUser()->getID() != $this->userID &&
		// 	!( in_array( $this->userID, $blockList ) )
		// ) {
		// 	$blockLink = '<a href="javascript:void(0);" rel="nofollow" class="comments-block-user" data-comments-safe-username="' .
		// 		htmlspecialchars( $this->username, ENT_QUOTES ) .
		// 		'" data-comments-comment-id="' . $this->id . '" data-comments-user-id="' .
		// 		$this->userID . "\">
		// 			<img src=\"{$wgExtensionAssetsPath}/Comments/images/block.svg\" border=\"0\" alt=\"\"/>
		// 		</a>";
		// }

		// Default avatar image, if SocialProfile extension isn't enabled
		global $wgCommentsDefaultAvatar;
		$avatarImg = '<img src="' . $wgCommentsDefaultAvatar . '" alt="" border="0" />';
		// If SocialProfile *is* enabled, then use its wAvatar class to get the avatars for each commenter
		if ( class_exists( 'wAvatar' ) ) {
			$avatar = new wAvatar( $this->userID, 'ml' );
			$avatarImg = $avatar->getAvatarAnchor() . "\n";
		}
		if ($this->ip == 0 && ($this->parentID != 0 || !$this->hasReply())){
			return '';
		}
	    $output = $templateParser->processTemplate(
		    'comments',
		    array(
		    	'commentID' => $this->id,
		    	'avatarImg' => $avatarImg,
		        'userLink' => $commentPoster,
		        'commentPosterLevel' => $commentPosterLevel,
		        'zan' => $this->getScoreHtml(),
		        'commentContent' => $this->getText(),
		        'timeago' => HuijiFunctions::getTimeAgo( strtotime( $this->date ) ),
		        // 'gender' => ,
		        'containerClass' => $containerClass,
		        'messageType' => $comment_class,
		        'actions' => $this->getActions($CommentReplyTo, $CommentReplyToGender),
		        'deleted' => $this->ip == 0 && $this->parentID == 0 && $this->hasReply(),
		        'deletedHtml' => "<div class='c-container c-item-del'>此条吐槽已被删除</div>",
		        'children' => $childrenCommentsHtml,
		    )
		);
		return $output;
		// if ( $this->ip == 0 && $this->parentID==0 && $this->hasReply() ) {
		// 	$output = "<div class='c-container c-item-del'>此条吐槽已被删除</div>";
		// 	return $output;
		// }elseif ($this->ip != 0) {
		// 	if ($this->parentID != 0){
		// 		$output = "<div id='comment-{$this->id}' class='c-item {$containerClass}'{$style}>" . "\n";
		// 	}
		// 	$output .= "<div class=\"c-avatar\">{$avatarImg}</div>" . "\n";
		// 	$output .= '<div class="c-container">' . "\n";
		// 	$output .= '<div class="c-user">' . "\n";
		// 	$output .= "{$commentPoster}";
		// 	$output .= "<span class=\"c-user-level\">{$commentPosterLevel}</span> {$blockLink}" . "\n";

		// 	wfSuppressWarnings(); // E_STRICT bitches about strtotime()
		// 	$output .= '<div class="c-score">' . "\n";
		// 	$output .= $this->getScoreHTML();
		// 	$output .= '</div>' . "\n";

		// 	$output .= '</div>' . "\n";
		// 	$output .= "<div class=\"c-comment {$comment_class}\">" . "\n";
		// 	$output .= $this->getText();
		// 	$output .= '</div>' . "\n";
		// 	$output .= '<div class="c-actions">' . "\n";
		// 	$output .= '<div class="c-time">' .
	 //        			wfMessage(
	 //        				'comments-time-ago',
	 //        				CommentFunctions::getTimeAgo( strtotime( $this->date ) )
	 //        			)->text() . '</div>' . "\n";
	 //        		wfRestoreWarnings();
		// 	//$output .= '<a href="' . htmlspecialchars( $this->page->title->getFullURL() ) . "#comment-{$this->id}\" rel=\"nofollow\">" .
		// 		$this->msg( 'comments-permalink' )->plain() . '</a> ';
		// 	if ( $replyRow || $dlt ) {
		// 		$output .= "{$replyRow} {$dlt}" . "\n";
		// 	}
		// 	$output .= '</div>' . "\n";

		// 	$output .= '</div>' . "\n";  // end of container
		// 	if ($this->parentID != 0){
		// 		$output .= '</div>' . "\n";
		// 	}
			
		// 	// $output .= '</div>' . "\n";
		// }else{
		// 	$output = '';
		// }
		return $output;
	}
	/**
	 * Get Html Actions for the logged in user
	 * 
	 */
	function getActions($CommentReplyTo, $CommentReplyToGender){
		$dlt = '';
		if ( $this->getUser()->isAllowed( 'commentadmin' ) ) {
			$dlt = ' | <span class="c-delete">' .
				'<a href="javascript:void(0);" rel="nofollow" class="comment-delete-link icon-trash" data-comment-id="' .
				$this->id . '"></a></span>';
		}

		// Reply Link 
		$replyRow = '';
		if ( $this->getUser()->isAllowed( 'comment' ) ) {
			if ( $this->parentID == 0 ) {
				$replyRow .= " | <a href=\"#replyto\" rel=\"nofollow\" class=\"comments-reply-to icon-bubble\" data-comment-id=\"{$this->id}\" data-comments-safe-username=\"" .
					htmlspecialchars( $CommentReplyTo, ENT_QUOTES ) . "\" data-comments-user-gender=\"" .
					htmlspecialchars( $CommentReplyToGender ) . '"></a>';
			} else {
				$replyRow .=" | <a href=\"#replyto\" rel=\"nofollow\" class=\"child-comments-reply-to icon-bubble\" data-comment-id=\"{$this->id}\" data-comment-parent-id=\"{$this->parentID}\" data-comments-safe-username=\"" .
					htmlspecialchars( $CommentReplyTo, ENT_QUOTES ) . "\" data-comments-user-gender=\"" .
					htmlspecialchars( $CommentReplyToGender ) . '"></a>';
			}
		}
		return $replyRow.$dlt;	
		
	}

	/**
	 * Get the HTML for the comment score section of the comment
	 *
	 * @return string
	 */
	function getScoreHTML() {
		$output = '';

		if ( $this->page->allowMinus == true || $this->page->allowPlus == true ) {
			$output .= '<span class="c-score-title">' .
				wfMessage( 'comments-score-text' )->text() .
				" <span id=\"Comment{$this->id}\">{$this->currentScore}</span></span>";

			// Voting is possible only when database is unlocked
			if ( !wfReadOnly() ) {
				// You can only vote for other people's comments, not for your own
				if ( $this->getUser()->getName() != $this->username ) {
					$output .= "<span id=\"CommentBtn{$this->id}\">";
					// $output .= "<a>".$this->username.'-'.$this->currentScore.$this->getUser()->getName()."</a>";
					if ( $this->page->allowPlus == true ) {
						$output .= $this->getVoteLink( 1 );
					}

					if ( $this->page->allowMinus == true ) {
						$output .= $this->getVoteLink( -1 );
					}
					$output .= '</span>';
				} else {
					$output .= wfMessage( 'word-separator' )->plain() . "<i class='icon-user'></i>";
				}
			}
		}

		return $output;
	}

	function sendEchoNotification( $type, $commentID, $mentionedUsers = null ){
		global $wgUser, $wgHuijiPrefix;
		$mComment = Comment::newFromID( $commentID );
		$page = $mComment->page;
		$content = $mComment->getText();
		$pageTitle = $page->title;
		if ($type === 'reply') {
			// send an echo notification
			// htmlspecialchars( $this->page->title->getFullURL() ) . "#comment-{$this->id}\"
			//$pageLink = htmlspecialchars( $page->title->getFullURL() )."#comment-{$commentID}";
			
			if ($mComment->parentID != 0){
				$mParentComment = Comment::newFromID($mComment->parentID);
			} else {
				return;
			}
			$userIdTo = $mParentComment->userID;
			$userIdFrom = $mComment->userID;
			$agent = User::newFromId($userIdFrom);

			EchoEvent::create( array(
			     'type' => 'comment-msg',
			     'extra' => array(
			         'comment-recipient-id' => $userIdTo,  
			         'comment-content' => $content,
			         'comment-id' => "comment-{$commentID}",
			         'interwiki' => $wgHuijiPrefix,
			     ),
			     'agent' => $agent,
			     'title' => $pageTitle,
			) );
		} else if ($type === 'plus') {
			$userIdTo = $mComment->userID;
			$userIdFrom = $wgUser;
			$agent = $wgUser;
			EchoEvent::create( array(
			     'type' => 'comment-msg',
			     'extra' => array(
			         'comment-recipient-id' => $userIdTo,  
			         'comment-content' => $content,
			         'comment-id' => "comment-{$commentID}",
			         'comment-plus' => true,
			         'interwiki' => $wgHuijiPrefix,
			     ),
			     'agent' => $agent,
			     'title' => $pageTitle,
			) );			
		} else if ($type === 'mention'){
			$agent = $wgUser;
			EchoEvent::create( array(
				'type' => 'comment-msg',
				'title' => $pageTitle,
				'extra' => array(
					'content' => $mComment->getText(),
					'mentioned-users' => $mentionedUsers,
					'comment-content' => $content,
			        'comment-id' => "comment-{$commentID}",
			        'interwiki' => $wgHuijiPrefix,
				),
				'agent' => $agent,
			) );
		}
	}
	/**
	* Used to pass Echo your definition for the notification category and the 
	* notification itself (as well as any custom icons).
	* 
    *
	*@see https://www.mediawiki.org/wiki/Echo_%28Notifications%29/Developer_guide
	*/
	public static function onBeforeCreateEchoEvent( &$notifications, &$notificationCategories, &$icons ) {
        $notificationCategories['comment-msg'] = array(
            'priority' => 3,
            'tooltip' => 'echo-pref-tooltip-comment-msg',
        );
        $notifications['comment-msg'] = array(
        	'category' => 'comment-msg',
        	'group' => 'positive',
        	'section' => 'message',
        	'presentation-model' => 'EchoCommentPresentationModel',
        	'bundle' => [
        		'web' => true,
        		'expandable' => true,
        	]
        );
        return true;
    }


	/**
	* Used to define who gets the notifications (for example, the user who performed the edit)
	* 
    *
	*@see https://www.mediawiki.org/wiki/Echo_%28Notifications%29/Developer_guide
	*/
	public static function onEchoGetDefaultNotifiedUsers( $event, &$users ) {
	 	switch ( $event->getType() ) {
	 		case 'comment-msg':
	 			$extra = $event->getExtra();
	 			if ( !$extra ){
	 				break;
	 			}
	 			if ( isset( $extra['mentioned-users'] ) ){
	 				$users = $extra['mentioned-users'];
	 				break;
	 			}
	 			if ( !isset( $extra['comment-recipient-id'] ) ) {
	 				break;
	 			}

	 			$recipientId = $extra['comment-recipient-id'];
	 			$recipient = User::newFromId($recipientId);
	 			$users[$recipientId] = $recipient;
	 			break;
	 	}
	 	return true;
	}

}
		
class EchoCommentPresentationModel extends EchoEventPresentationModel {
	public function canRender() {
		return (bool)$this->event->getTitle();
	}
	public function getIconType() {
		return 'chat';
	}
	public function getHeaderMessage() {
		if ( $this->isBundled() ) {
			$msg = $this->msg( 'notification-bundle-header-comment-msg' );
			$msg->params( $this->getBundleCount() );
			return $msg;
		}
		if ($this->event->getExtraParam('mentioned-users')){
			$msg = $this->getMessageWithAgent('notification-header-comment-mentioned');
			return $msg;
		}
		if ($this->event->getExtraParam('comment-plus')){
			$msg = $this->getMessageWithAgent('notification-header-comment-plus');
			return $msg;
		}
		$msg = parent::getHeaderMessage();
		return $msg;
	}
	public function getBodyMessage() {
		$excerpt = $this->event->getExtraParam( 'comment-content' );
		if ( $excerpt ) {
			$msg = new RawMessage( '$1' );
			$msg->plaintextParams( $excerpt );
			return $msg;
		}
	}
	public function getPrimaryLink() {
		$title = $this->event->getTitle();
		// Make a link to #flow-post-{postid}
		$title = Title::makeTitle(
			$title->getNamespace(),
			$title->getDBKey(),
			$this->event->getExtraParam('comment-id'),
			''
		);
		return [
			'url' => $title->getFullURL(),
			'label' => $this->msg( 'notification-view-comment' )->text(),
		];
	}
	public function getSecondaryLinks() {
		return [ $this->getAgentLink() ];
	}
}