<?php
/**
 * Comments of the Day parser hook -- shows the five newest comments that have
 * been sent within the last 24 hours.
 *
 * @file
 * @ingroup Extensions
 * @date 21 September 2014
 */
if ( !defined( 'MEDIAWIKI' ) ) {
	die();
}

$wgHooks['ParserFirstCallInit'][] = 'wfCommentsOfTheDay';

/**
 * Register the new <commentsoftheday /> parser hook with the Parser.
 *
 * @param Parser $parser Instance of Parser
 * @return bool
 */
function wfCommentsOfTheDay( &$parser ) {
	$parser->setHook( 'commentsoftheday', 'getCommentsOfTheDay' );
	return true;
}

/**
 * Get comments of the day -- five newest comments within the last 24 hours
 *
 * @return string HTML
 */
function getCommentsOfTheDay( $input, $args, $parser ) {
	global $wgMemc, $wgHasComments, $wgCommentsSortDescending;

	$out = $parser->getOutput();
	$out->addModules( array('ext.comments.js','ext.comments.css') );
	$out->addJsConfigVars( array( 'wgCommentsSortDescending' => $wgCommentsSortDescending ) );
	$oneDay = 60 * 60 * 24;
	$oneHour = 60 * 60;

	// Try memcached first
	$key = wfMemcKey( 'comments-of-the-day', 'standalone-hook-new' );
	$data = $wgMemc->get( $key );

	if ( $data ) { // success, got it from memcached!
		$comments = $data;
	} elseif ( !$data || $args['nocache'] ) { // just query the DB
		$dbr = wfGetDB( DB_SLAVE );

		$res = $dbr->select(
			// array( 'Comments', 'page' ),
			array( 'Comments' ),
			array(
				'Comment_Username', 'Comment_IP', 'Comment_Text',
				'Comment_Date', 'UNIX_TIMESTAMP(Comment_Date) AS timestamp',
				'Comment_User_Id', 'CommentID', 'Comment_Parent_ID',
				'Comment_Page_ID'
			),
			array(
				// 'comment_page_id = page_id',
				'UNIX_TIMESTAMP(Comment_Date) > ' . ( time() - ( $oneDay ) )
			),
			__METHOD__
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
				'Comment_user_id' => $row->Comment_User_Id,
				'Comment_user_points' => ( isset( $row->stats_total_points ) ? number_format( $row->stats_total_points ) : 0 ),
				'CommentID' => $row->CommentID,
				'Comment_Parent_ID' => $row->Comment_Parent_ID,
				'thread' => $thread,
				'timestamp' => $row->timestamp
			);

			$page = new CommentsPage( $row->Comment_Page_ID, new RequestContext() );
			$comments[] = new Comment( $page, new RequestContext(), $data );
		}

		usort( $comments, array( 'CommentFunctions', 'sortCommentScore' ) );
		$comments = array_slice( $comments, 0, 5 );

		$wgMemc->set( $key, $comments, $oneHour );
	}

	$commentOutput = '<ul class="cod-ul">';

	foreach ( $comments as $comment ) {
		$commentOutput .= $comment->displayForCommentOfTheDay();
	}

	$output = '</ul>';
	if ( !empty( $comments ) ) {
		$output .= $commentOutput;
	} else {
		$output .= $commentOutput.'<p>'.wfMessage( 'comments-no-comments-of-day' )->plain().'</p>';
	}
	return $output;
}