window.insertTags = function( tagOpen, tagClose, sampleText ) {
//	$currentFocused = $( '.emoji-wysiwyg-editor' );
//	if ( $currentFocused && $currentFocused.length ) {
//		$currentFocused.textSelection(
//			'encapsulateSelection', {
//				pre: tagOpen,
//				peri: sampleText,
//				post: tagClose
//			}
//		);
//	}
    var area = $( '.emoji-wysiwyg-editor')[0];
//    area.focus();
//    if (area.createTextRange) {
//        var caretPos = document.selection.createRange().duplicate();
//        document.selection.empty();
//        caretPos.text = tagOpen+sampleText+tagClose;
//    } else if (area.setSelectionRange) {
//        var rangeStart = area.selectionStart;
//        var rangeEnd = area.selectionEnd;
//        var tempStr1 = area.value.substring(0, rangeStart);
//        var tempStr2 = area.value.substring(rangeEnd);
//        area.value = tempStr1 + textFeildValue + tempStr2;
//        area.blur();
//    }
    var userSelection, text;
    function setPos(pos){
        console.log(pos);
        var isContentEditable = area.contentEditable === 'true';
        if (window.getSelection) {
            //contenteditable
            if (isContentEditable) {
                area.focus();
                window.getSelection().collapse(area.firstChild, pos);
            }
            //textarea
            else
                area.setSelectionRange(pos, pos);
        }
    }
    if (window.getSelection) {
        //现代浏览器
        userSelection = window.getSelection();
    } else if (document.selection) {
        //IE浏览器 考虑到Opera，应该放在后面
        userSelection = document.selection.createRange();
    }
    if (!(text = userSelection.text)) {
        text = userSelection;
    }
    if(text == '') {
        area.focus();
        var range1 = window.getSelection().getRangeAt(0),
            range2 = range1.cloneRange();
        range2.selectNodeContents(area);
        range2.setEnd(range1.endContainer, range1.endOffset);
//                    console.log(range2.toString().length);
        var start = range2.toString().length;
        var content = $('.mention-area.emoji-wysiwyg-editor').text().substring(0, start) + tagOpen + sampleText + tagClose + $('.mention-area.emoji-wysiwyg-editor').text().substring(start);
        $('.emoji-wysiwyg-editor').text(content).val(content);
        setPos(start+(tagOpen + sampleText + tagClose).length)
    }else{
        console.log(userSelection);
        var start = Math.min(userSelection.anchorOffset,userSelection.focusOffset),
        end = Math.max(userSelection.anchorOffset,userSelection.focusOffset);
        var content = $('.mention-area.emoji-wysiwyg-editor').text().substring(0, start) + tagOpen +text + tagClose + $('.mention-area.emoji-wysiwyg-editor').text().substring(end);
        $('.emoji-wysiwyg-editor').text(content).val(content);
        area.blur();
    }
}