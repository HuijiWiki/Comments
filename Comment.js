/*global mw,$*/
/**
 * JavaScript for the Comments extension.
 * Rewritten by Jack Phoenix <jack@countervandalism.net> to be more
 * object-oriented.
 *
 * @file
 * @date 6 December 2013
 */
var Comment = {
    submitted: 0,
    isBusy: false,
    timer: '', // has to have an initial value...
    updateDelay: 7000,
    LatestCommentID: '',
    CurLatestCommentID: '',
    pause: 0,

    /**
     * When a comment's author is ignored, "Show Comment" link will be
     * presented to the user.
     * If the user clicks on it, this function is called to show the hidden
     * comment.
     */
    show: function( id ) {
        $( '#ignore-' + id ).hide( 300 );
        $( '#comment-' + id ).show( 300 );
    },

    /**
     * This function is called whenever a user clicks on the "block" image to
     * block another user's comments.
     *
     * @param username String: name of the user whose comments we want to block
     * @param userID Integer: user ID number of the user whose comments we
     *                         want to block (or 0 for anonymous users)
     * @param commentID Integer: comment ID number
     */
    blockUser: function( username, userID, commentID ) {
        var api = new mw.Api();
        var message;

        // Display a different message depending on whether we're blocking an
        // anonymous user or a registered one.
        if ( !userID || userID === 0 ) {
            message = mw.msg( 'comments-block-warning-anon' );
        } else {
            message = mw.msg( 'comments-block-warning-user', username );
        }

        if ( window.confirm( message ) ) {
            api.postWithToken('edit', { 'action': 'commentblock', 'format': 'json', 'commentID': commentID })
                .done(function( response ) {
                    if ( response.commentblock.ok ) {
                        $( 'a.comments-block-user[data-comments-user-id=' + userID + ']' ).parents( '.c-item' ).hide( 300 )
                            .prev().show( 300 );
                    }
                } );
            // $.ajax( {
            // 	url: mw.config.get( 'wgScriptPath' ) + '/api.php',
            // 	data: { 'action': 'commentblock', 'format': 'json', 'commentID': commentID, 'token':token },
            // 	cache: false,
            // 	method: 'post'
            // } ).done( function( response ) {
            // 	if ( response.commentblock.ok ) {
            // 		$( 'a.comments-block-user[data-comments-user-id=' + userID + ']' ).parents( '.c-item' ).hide( 300 )
            // 																									.prev().show( 300 );
            // 	}
            // } );
        }
    },

    /**
     * This function is called whenever a user clicks on the "Delete Comment"
     * link to delete a comment.
     *
     * @param commentID Integer: comment ID number
     */
    deleteComment: function( commentID ) {
        var api = new mw.Api();
        if ( window.confirm( mw.msg( 'comments-delete-warning' ) ) ) {
            api.postWithToken('edit', { 'action': 'commentdelete', 'format': 'json', 'commentID': commentID})
                .done( function( response ) {
                    if ( response.commentdelete.ok ) {
                        $( '#comment-' + commentID ).hide( 2000 );
                    }
                } );
        }
    },

    /**
     * Vote for a comment.
     *
     * @param commentID Integer: comment ID number
     * @param voteValue Integer: vote value
     */
    vote: function( commentID, voteValue ) {
        var api = new mw.Api();
        // var token = new mw.Api().getToken('edit').done().token;
        api.postWithToken('edit', { 'action': 'commentvote', 'format': 'json', 'commentID': commentID, 'voteValue': voteValue})
            .done( function( response ) {
                $( '#comment-' + commentID + ' > .hj-media-body > .c-score' ).html( response.commentvote.html )
                    .html( $( '#comment-' + commentID + '  > .hj-media-body > .c-score' ).text() );
            } );
    },

    /**
     * @param pageID Integer: page ID
     * @param order Sorting order
     * @param end Scroll to bottom after?
     * @param cpage Integer: comment page number (used for pagination)
     */
    viewComments: function( pageID, order, parentID, cpage, type ) {
        $.ajax( {
            url: mw.config.get( 'wgScriptPath' ) + '/api.php',
            data: { 'action': 'commentlist', 'format': 'json', 'pageID': pageID, 'order': order, 'pagerPage': cpage },
            cache: false
        } ).done( function( response ) {
            document.getElementById( 'allcomments' ).innerHTML = response.commentlist.html;
            Comment.submitted = 0;
            $('#tc_comment').html(mw.msg('comments-post'));
            if (type!="page") {
                window.location.hash = 'comment-' + parentID;
            };
        } );
    },

    /**
     * Submit a new comment.
     */
    submit: function() {
        var api = new mw.Api();
        if (mw.config.get('wgUserName') == null){
	        window.location.href = "/wiki/Special:Login";            
            return;
        }
        if ( Comment.submitted === 0 ) {
            Comment.submitted = 1;
            $('#tc_comment').prepend('<i class="fa fa-spinner fa-spin"></i>');
            var pageID = document.commentForm.pageId.value;
            var parentID;
            if ( !document.commentForm.commentParentId.value ) {
                parentID = 0;
            } else {
                parentID = document.commentForm.commentParentId.value;
            }
            var commentText = $('.emoji-wysiwyg-editor').text();
            api.postWithToken('edit', { 'action': 'commentsubmit', 'format': 'json', 'pageID': pageID, 'parentID': parentID, 'commentText': commentText })
                .done(function( response ) {
                    if ( response.commentsubmit.ok ) {
                        $('.mention-area').text('').val('');
                        Comment.viewComments( document.commentForm.pageId.value, 0, parentID, document.commentForm.cpage.value,'' );
                        $('#comment').trigger('blur');
                    } else {
                        window.alert( response.responseText );
                        Comment.submitted = 0;
                        $('#tc_comment').html(mw.msg('comments-post'));
                    }
                } );
            // $.ajax( {
            // 	url: mw.config.get( 'wgScriptPath' ) + '/api.php',
            // 	data: { 'action': 'commentsubmit', 'format': 'json', 'pageID': pageID, 'parentID': parentID, 'commentText': commentText, 'token':token },
            // 	cache: false,
            // 	method: 'post'
            // } ).done( function( response ) {
            // 	if ( response.commentsubmit.ok ) {
            // 		document.commentForm.commentText.value = '';
            // 		Comment.viewComments( document.commentForm.pageId.value, 0, parentID, document.commentForm.cpage.value,'' );
            //                  			$('#comment').trigger('blur');
            // 	} else {
            // 		window.alert( response.responseText );
            // 		Comment.submitted = 0;
            // 		$('#tc_comment').html(mw.msg('comments-post'));
            // 	}
            // } );

            Comment.cancelReply();
        }
    },

    /**
     * Toggle comment auto-refreshing on or off
     *
     * @param status
     */
    toggleLiveComments: function( status ) {
        if ( status ) {
            Comment.pause = 0;
        } else {
            Comment.pause = 1;
        }
        var msg;
        if ( status ) {
            msg = mw.msg( 'comments-auto-refresher-pause' );
        } else {
            msg = mw.msg( 'comments-auto-refresher-enable' );
        }

        $( 'body' ).on( 'click', 'div#spy a', function() {
            Comment.toggleLiveComments( ( status ) ? 0 : 1 );
        } );
        $( 'div#spy a' ).text( msg );

        if ( !Comment.pause ) {
            Comment.LatestCommentID = document.commentForm.lastCommentId.value;
            Comment.timer = setTimeout(
                function() { Comment.checkUpdate(); },
                Comment.updateDelay
            );
        }
    },

    checkUpdate: function() {
        if ( Comment.isBusy ) {
            return;
        }
        var pageID = document.commentForm.pageId.value;

        $.ajax( {
            url: mw.config.get( 'wgScriptPath' ) + '/api.php',
            data: { 'action': 'commentlatestid', 'format': 'json', 'pageID': pageID },
            cache: false
        } ).done( function( response ) {
            if ( response.commentlatestid && response.commentlatestid.id ) {
                // Get last new ID
                Comment.CurLatestCommentID = response.commentlatestid.id;
                if ( Comment.CurLatestCommentID !== Comment.LatestCommentID ) {
                    Comment.viewComments( document.commentForm.pageId.value, 0, 1, document.commentForm.cpage.value,'' );
                    Comment.LatestCommentID = Comment.CurLatestCommentID;
                }
            }

            Comment.isBusy = false;
            if ( !Comment.pause ) {
                clearTimeout( Comment.timer );
                Comment.timer = setTimeout(
                    function() { Comment.checkUpdate(); },
                    Comment.updateDelay
                );
            }
        } );

        Comment.isBusy = true;
        return false;
    },

    /**
     * Show the "reply to user X" form
     *
     * @param parentId Integer: parent comment (the one we're replying to) ID
     * @param poster String: name of the person whom we're replying to
     * @param posterGender String: gender of the person whom we're replying to
     */
    reply: function( parentId, poster, posterGender ) {
        $( '#replyto' ).text(
                mw.msg( 'comments-reply-to', poster, posterGender ) + ' ('
        );
        $( '<a>', {
            class: 'comments-cancel-reply-link',
            style: 'cursor:pointer',
            text: mw.msg( 'comments-cancel-reply' )
        } ).appendTo( '#replyto' );
        $( '#replyto' ).append( ') <br />' );

        document.commentForm.commentParentId.value = parentId;
        $( '#comment' ).focus();
    },

    childReply: function ( parentId, poster, posterGender, parentOfParentId ) {
        $( '#replyto' ).text(
                mw.msg( 'child-comments-reply-to', poster, posterGender ) + ' ('
        );
        $( '<a>', {
            class: 'comments-cancel-reply-link',
            style: 'cursor:pointer',
            text: mw.msg( 'comments-cancel-reply' )
        } ).appendTo( '#replyto' );
        $( '#replyto' ).append( ') <br />' );
        /*$( '#comment').val(
            mw.msg( 'child-comments-reply-to', poster, posterGender )
        );*/
        var rmsg = mw.msg( 'child-comments-reply-to', poster, posterGender );
        $('.mention-area').text(rmsg).val(rmsg);
        document.commentForm.commentParentId.value = parentOfParentId;
        $( '#comment' ).focus();
    },

    cancelReply: function() {
        document.getElementById( 'replyto' ).innerHTML = '';
        document.commentForm.commentParentId.value = '';
    }
};

$( document ).ready( function() {
    // Important note: these are all using $( 'body' ) as the selector
    // instead of the class/ID/whatever so that they work after viewComments()
    // has been called (i.e. so that "Delete comment", reply, etc. links
    // continue working after you've submitted a comment yourself)

    // "Sort by X" feature
    $( 'body' ).on( 'change', 'select[name="TheOrder"]', function() {
        Comment.viewComments(
            mw.config.get( 'wgArticleId' ), // or we could use $( 'input[name="pid"]' ).val(), too
            $( this ).val(),
            0,
            document.commentForm.cpage.value,
            ''
        );
    } )

    $('body').on('click','.comment-more',function(){
        $('#hotcomments').hide();

        Comment.viewComments(
            mw.config.get( 'wgArticleId' ), // or we could use $( 'input[name="pid"]' ).val(), too
            1,
            0,
            document.commentForm.cpage.value,
            ''
        );
    })

        // Comment auto-refresher
        .on( 'click', 'div#spy a', function() {
            Comment.toggleLiveComments( 1 );
        } )

        // Voting links
        .on( 'click', 'a#comment-vote-link', function() {
            var that = $( this );
            Comment.vote(
                that.data( 'comment-id' ),
                that.data( 'vote-type' )
            );
        } )

        // "Block this user" links
        .on( 'click', 'a.comments-block-user', function() {
            var that = $( this );
            Comment.blockUser(
                that.data( 'comments-safe-username' ),
                that.data( 'comments-user-id' ),
                that.data( 'comments-comment-id' )
            );
        } )

        // "Delete Comment" links
        .on( 'click', 'a.comment-delete-link', function() {
            Comment.deleteComment( $( this ).data( 'comment-id' ) );
        } )

        // "Show this hidden comment" -- comments made by people on the user's
        // personal block list
        .on( 'click', 'div.c-ignored-links a', function() {
            Comment.show( $( this ).data( 'comment-id' ) );
        } )

        // Reply links
        .on( 'click', 'a.child-comments-reply-to', function() {
            Comment.childReply(
                $( this ).data( 'comment-id' ),
                $( this ).data( 'comments-safe-username' ),
                $( this ).data( 'comments-user-gender' ),
                $( this ).data( 'comment-parent-id')
            );
        } )

        // Reply links
        .on( 'click', 'a.comments-reply-to', function() {
            Comment.reply(
                $( this ).data( 'comment-id' ),
                $( this ).data( 'comments-safe-username' ),
                $( this ).data( 'comments-user-gender' )
            );

        } )

        // "Reply to <username>" links
        .on( 'click', 'a.comments-cancel-reply-link', function() {
            Comment.cancelReply();
        } )

        // Handle clicks on the submit button (previously this was an onclick attr)
        .on( 'click touchstart', '#tc_comment', function(event) {
            event.preventDefault();
            if ($('.emoji-wysiwyg-editor').text()=='') {
                alert('请输入吐槽内容');
            }else{
                Comment.submit();
            }
        } )

        // Change page
        .on( 'click', 'li.c-pager-item a.c-pager-link', function() {

            var ord = 0,
                commentsBody = $( this ).parents( 'div.comments-body:first' );

            if ( commentsBody.length > 0 ) {
                var ordCrtl = commentsBody.first().find( 'select[name="TheOrder"]:first' );
                if ( ordCrtl.length > 0 ) {
                    ord = ordCrtl.val();
                }
            }
            if ( $( this ).data( 'cpage' ) != 1 ) {
                $('#hotcomments').hide();
            }else{
                $('#hotcomments').show();
            };

            Comment.viewComments(
                mw.config.get( 'wgArticleId' ), // or we could use $( 'input[name="pid"]' ).val(), too
                ord,
                0,
                $( this ).data( 'cpage' ),
                'page'
            );
        } )
        //tucao Login
        .on( 'click', '#TcLogin', function(){
            window.location = $('.login').attr('href');
            return;
        } )
        // tucao input
        .on('focus','#comment', function () {
            if($('.navbar-right').hasClass('navbar-login')){
                $('.user-login').modal();
            }else{
                $(this).addClass('focus');
            }
        })
//        .on('blur','#comment',function(){
//            $(this).removeClass('focus');
//        })
        .on('mousedown touchstart',function(){
            $('.custom-face').hide();
        })
        .on('mousedown touchstart','.custom-face,.emoji-wysiwyg-editor',function(e){
            e.stopPropagation();
        })
} );
