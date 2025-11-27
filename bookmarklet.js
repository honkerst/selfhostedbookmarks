/**
 * Bookmarklet code - Save this as a bookmark
 * 
 * IMPORTANT: Copy ONLY the single-line javascript:... code below (starting with "javascript:")
 * Do NOT include the comment lines or line breaks.
 * 
 * To use:
 * 1. Copy the entire javascript:... line below (it should be ONE continuous line)
 * 2. Create a new bookmark in your browser
 * 3. Set the bookmark URL to the copied code
 * 4. Test it by clicking the bookmark while on any webpage
 */

// ============================================
// COPY THIS LINE (the entire line, no breaks):
// ============================================

javascript:(function(){var q=location.href;var p=document.title;var d='';if(document.getSelection){d=document.getSelection().toString();}else if(window.getSelection){d=window.getSelection().toString();}var url='https://bookmarks.thoughton.co.uk/bookmarklet-popup.php?url='+encodeURIComponent(q)+'&title='+encodeURIComponent(p)+'&description='+encodeURIComponent(d);window.open(url,'bookmarklet','toolbar=no,scrollbars=yes,width=600,height=550,resizable=yes');})();

// ============================================
// 
// READABLE VERSION (for reference):
// 
// javascript:(function(){
//   var q = location.href;
//   var p = document.title;
//   var d = '';
//   if (document.getSelection) {
//     d = document.getSelection().toString();
//   } else if (window.getSelection) {
//     d = window.getSelection().toString();
//   }
//   var url = 'https://bookmarks.thoughton.co.uk/bookmarklet-popup.php?url=' + 
//             encodeURIComponent(q) + 
//             '&title=' + encodeURIComponent(p) + 
//             '&description=' + encodeURIComponent(d);
//   window.open(url, 'bookmarklet', 'toolbar=no,scrollbars=yes,width=600,height=550,resizable=yes');
// })();
//
// ============================================
