/**
 * IABookreader 4.x depends on $ alias for its plugins. Thank you, @giancarlobi
 *
 * Drupal 11 deprecates Jquery 3.x. IABookreader still uses it. Brings deprecated
 * Function into the $ global name.
 */
var $ = window.jQuery;


if (!$.unique) {
  $.unique = $.uniqueSort;
}

$.isArray = Array.isArray;

$.isFunction = function( obj ) {

  // Support: Chrome <=57, Firefox <=52
  // In some browsers, typeof returns "function" for HTML <object> elements
  // (i.e., `typeof document.createElement( "object" ) === "function"`).
  // We don't want to classify *any* DOM node as a function.
  // Support: QtWeb <=3.8.5, WebKit <=534.34, wkhtmltopdf tool <=0.12.5
  // Plus for old WebKit, typeof returns "function" for HTML collections
  // (e.g., `typeof document.getElementsByTagName("div") === "function"`). (gh-4756)
  return typeof obj === "function" && typeof obj.nodeType !== "number" &&
    typeof obj.item !== "function";
};

$.now = Date.now;

$.toType = function( obj ) {
  if ( obj == null ) {
    return obj + "";
  }

  // Support: Android <=2.3 only (functionish RegExp)
  return typeof obj === "object" || typeof obj === "function" ?
    class2type[ toString.call( obj ) ] || "object" :
    typeof obj;
}

$.isNumeric = function( obj ) {

  // As of jQuery 3.0, isNumeric is limited to
  // strings and numbers (primitives or objects)
  // that can be coerced to finite numbers (gh-2662)
  var type = jQuery.type( obj );
  return ( type === "number" || type === "string" ) &&

    // parseFloat NaNs numeric-cast false positives ("")
    // ...but misinterprets leading-number strings, particularly hex literals ("0x...")
    // subtraction forces infinities to NaN
    !isNaN( obj - parseFloat( obj ) );
};

$.trim = function( text ) {
  return text == null ?
    "" :
    ( text + "" ).replace( rtrim, "$1" );
};
