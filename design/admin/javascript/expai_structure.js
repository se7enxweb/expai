/*!
 * expai - Exponential Intelligence
 * Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * GNU General Public License v2.0 (or any later version)
 *
 * The structure of a rich text value (doc/edit-view-integration.md "Structure is never lost silently"). Loaded on
 * every admin page before expai.js (design.ini [JavaScriptSettings] BackendJavaScriptList[]); also a CommonJS module,
 * so tests/js/expai_structure.test.mjs runs it with node. No DOM: it reads the markup with a small tag scanner, so
 * it works on the HTML of the Online Editor (TinyMCE), of Tiptap (exp_oe_tiptap) and on the simplified XML of a
 * textarea without an editor alike.
 *
 *   ExpAiStructure.count( value )              { embed, table, cell, th, merge, custom, anchor, literal, link, otherlink, heading, attribute }
 *   ExpAiStructure.loss( current, proposed )   [ { kind, lost } ]: the kinds the proposed value has fewer of
 *   ExpAiStructure.describe( loss, strings )   "2 embedded objects, 1 table" (strings lost_<kind>_1 / lost_<kind>_n)
 *   ExpAiStructure.toText( html )              the value as plain text with paragraphs, for a preview
 *   ExpAiStructure.same( a, b )                the two values are the same, white space between tags aside
 */
(function ( root, factory ) {
    'use strict';
    var api = factory();
    if ( typeof module === 'object' && module.exports ) {
        module.exports = api;
    } else {
        root.ExpAiStructure = api;
    }
})( typeof window !== 'undefined' ? window : this, function () {
    'use strict';

    // cell: td and th; th: header cells; merge: what colspan/rowspan above 1 span beyond their own cell;
    // link: internal links; otherlink: links the answer cannot keep either (#fragment, tel:, ftp:, www., //host: only
    // http(s) and mailto survive the server's cleaning); heading: h1, h5 and h6 (the answer has h2-h4 only);
    // attribute: each formatting or link attribute (SIGNIFICANT, custom:*, an own class, the id of a link)
    var KINDS = [ 'embed', 'table', 'cell', 'th', 'merge', 'custom', 'anchor', 'literal', 'link', 'otherlink', 'heading', 'attribute' ];

    var VOID = { area: 1, base: 1, br: 1, col: 1, embed: 1, hr: 1, img: 1, input: 1, link: 1, meta: 1, param: 1, source: 1, track: 1, wbr: 1 };

    var EMBED_ELEMENTS = { embed: 1, 'embed-inline': 1, object: 1, iframe: 1, video: 1, audio: 1 };

    // attributes that carry formatting or the behaviour of a link, counted one by one
    var SIGNIFICANT = { align: 1, valign: 1, width: 1, border: 1, cellpadding: 1, cellspacing: 1, customattributes: 1,
                        target: 1, title: 1, view: 1, style: 1 };

    // elements whose content is not markup: skipped up to their end tag
    var RAW = { script: 1, style: 1 };

    // in a value with an unclosed embed or literal, the start of one of these ends a skipped part that has no open
    // element of its own
    var BOUNDARY = { p: 1, h1: 1, h2: 1, h3: 1, h4: 1, h5: 1, h6: 1, table: 1, ul: 1, ol: 1, pre: 1, literal: 1,
                     paragraph: 1, header: 1, section: 1, blockquote: 1 };

    // comments and CDATA sections (unclosed: to the end) are no markup; no "<" in names and unquoted values: an
    // unclosed tag ends at the next one, so the scan stays linear
    var TAG = /<!--[\s\S]*?(?:-->|$)|<!\[CDATA\[[\s\S]*?(?:\]\]>|$)|<(\/?)([a-zA-Z][\w:.-]*)((?:\s+[^\s"'<>\/=]+(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s"'<>]+))?)*)\s*(\/?)>/g;
    var ATTRIBUTE = /([^\s"'<>\/=]+)(?:\s*=\s*("[^"]*"|'[^']*'|[^\s"'<>]+))?/g;

    function attributes( text ) {
        var out = {}, m;
        ATTRIBUTE.lastIndex = 0;
        while ( ( m = ATTRIBUTE.exec( text || '' ) ) ) {
            var value = m[2] === undefined ? '' : m[2];
            if ( value.charAt( 0 ) === '"' || value.charAt( 0 ) === "'" ) {
                value = value.slice( 1, -1 );
            }
            out[m[1].toLowerCase()] = value;
        }
        return out;
    }

    function has( attrs, name ) {
        return Object.prototype.hasOwnProperty.call( attrs, name );
    }

    function classes( attrs ) {
        return String( attrs['class'] || '' ).split( /\s+/ ).filter( Boolean );
    }

    /** The classes the editors add themselves (ezoeItem..., mceItem..., ezoeAlign...) are not the author's */
    function ownClasses( attrs ) {
        return classes( attrs ).filter( function ( c ) { return !/^(ezoe|mce)/i.test( c ); } );
    }

    /**
     * A link to an object or node of the site, or to a path on it (/about): the answer cannot keep it. Links with a
     * scheme of their own (http, https, mailto, tel, ftp ...), without one (www.example.com) and to a #fragment are
     * external; the answer keeps http(s) and mailto, the others are counted as otherlink (lostLink).
     */
    function internalLink( attrs ) {
        if ( has( attrs, 'object_id' ) || has( attrs, 'node_id' ) ) {
            return true;
        }
        var href = String( attrs.href || '' ).trim();
        return /^(ezobject|eznode|ezcontent|ezlocation|ezurl):/i.test( href ) || ( href.charAt( 0 ) === '/' && href.charAt( 1 ) !== '/' );
    }

    /** A link with an address the server's cleaning removes: anything but http(s) and mailto (internal ones are counted as link) */
    function lostLink( attrs ) {
        var href = String( attrs.href || '' ).trim();
        return href !== '' && !/^(https?:\/\/[^\s"<>]+|mailto:[^\s"<>]+)$/i.test( href );
    }

    /**
     * The kind of one opening tag, or null. skip: its content is not part of the text (the editor's preview of an
     * embed, the text of a literal).
     */
    function classify( name, attrs ) {
        var cls = classes( attrs );
        var element = String( attrs['data-ezelement'] || '' ).toLowerCase();
        if ( EMBED_ELEMENTS[name] || /^ez(object|node)_\d+/i.test( attrs.id || '' ) || /^ezembed/.test( element )
             || cls.indexOf( 'ezoeItemNonEditable' ) !== -1 || cls.some( function ( c ) { return /^ezoeItemContentType/.test( c ); } ) ) {
            return { kind: 'embed', skip: true };
        }
        if ( name === 'custom' || name === 'sub' || name === 'sup' || /^eztemplate/.test( element )
             || cls.indexOf( 'ezoeItemCustomTag' ) !== -1 || String( attrs.type || '' ).toLowerCase() === 'custom' ) {
            return { kind: 'custom' };
        }
        if ( name === 'img' ) {
            return { kind: 'embed' };
        }
        if ( name === 'table' ) {
            return { kind: 'table' };
        }
        if ( name === 'pre' || name === 'literal' ) {
            return { kind: 'literal', skip: true };
        }
        if ( name === 'anchor' || ( name === 'a' && !has( attrs, 'href' ) && ( attrs.name || attrs.id ) ) || ( name === 'header' && attrs.anchor_name ) ) {
            return { kind: 'anchor' };
        }
        if ( ( name === 'a' || name === 'link' ) && internalLink( attrs ) ) {
            return { kind: 'link' };
        }
        if ( ( name === 'a' || name === 'link' ) && lostLink( attrs ) ) {
            return { kind: 'otherlink' };
        }
        if ( name === 'h1' || name === 'h5' || name === 'h6' ) {
            return { kind: 'heading' };
        }
        return null;
    }

    /** The formatting and link attributes of one element (none for an embed: it is counted as a whole) */
    function attributeCount( name, attrs, kind ) {
        if ( kind === 'embed' ) {
            return 0;
        }
        var n = Object.keys( attrs ).filter( function ( a ) { return SIGNIFICANT[a] || a.indexOf( 'custom:' ) === 0; } ).length;
        // the class of a custom tag is its name
        if ( kind !== 'custom' && ownClasses( attrs ).length ) {
            n++;
        }
        if ( ( name === 'a' || name === 'link' ) && has( attrs, 'href' ) && attrs.id ) {
            n++;
        }
        return n;
    }

    function span( value ) {
        var n = parseInt( value, 10 );
        return n > 1 ? Math.min( n, 1000 ) - 1 : 0;
    }

    /**
     * How many structure-bearing elements (and attributes) of each kind the value has. An embed or literal that is
     * still open at the end does not swallow the rest: the value is read once more, and then a skipped part also ends
     * where the next block starts (BOUNDARY). Two passes at most, so the scan stays linear.
     */
    function count( value ) {
        var text = String( value === undefined || value === null ? '' : value );
        return scan( text, false ) || scan( text, true );
    }

    /** @return the counts, or null when a skipped part is still open at the end and boundaries is false */
    function scan( text, boundaries ) {
        var out = {};
        KINDS.forEach( function ( k ) { out[k] = 0; } );
        var skip = null, m;
        TAG.lastIndex = 0;
        while ( ( m = TAG.exec( text ) ) ) {
            if ( !m[2] ) {
                continue; // a comment or a CDATA section
            }
            var name = m[2].toLowerCase(), closing = m[1] === '/', selfClosing = m[4] === '/' || !!VOID[name];
            if ( !closing && RAW[name] ) {
                // up to its end tag (none: the rest), wherever it is
                var end = new RegExp( '</' + name + '\\s*>', 'gi' );
                end.lastIndex = TAG.lastIndex;
                TAG.lastIndex = end.exec( text ) ? end.lastIndex : text.length;
                continue;
            }
            if ( skip ) {
                if ( selfClosing ) {
                    continue;
                }
                if ( closing ) {
                    if ( skip.open.length && skip.open[skip.open.length - 1] === name ) {
                        skip.open.pop();
                    } else {
                        // the skipped element's own end, or the end of one around it: either way the part is over
                        skip = null;
                    }
                    continue;
                }
                if ( !boundaries || skip.open.length || !BOUNDARY[name] ) {
                    skip.open.push( name );
                    continue;
                }
                skip = null; // an unclosed embed or literal ends where the next block starts; the block counts
            }
            if ( closing ) {
                continue;
            }
            var attrs = attributes( m[3] );
            var found = classify( name, attrs );
            if ( found ) {
                out[found.kind]++;
            }
            if ( name === 'td' || name === 'th' ) {
                out.cell++;
                out.th += name === 'th' ? 1 : 0;
                out.merge += span( attrs.colspan ) + span( attrs.rowspan );
            }
            out.attribute += attributeCount( name, attrs, found && found.kind );
            if ( found && found.skip && !selfClosing ) {
                skip = { open: [] };
            }
        }
        return skip && !boundaries ? null : out;
    }

    /** The kinds the proposed value would lose: [ { kind, lost } ], in the order of KINDS; [] when nothing is lost */
    function loss( current, proposed ) {
        var before = count( current ), after = count( proposed );
        return KINDS.filter( function ( k ) { return after[k] < before[k]; } ).map( function ( k ) {
            return { kind: k, lost: before[k] - after[k] };
        } );
    }

    /** "2 embedded objects, 1 table": strings lost_<kind>_1 and lost_<kind>_n with %count, joined with ", " */
    function describe( list, strings ) {
        strings = strings || {};
        return ( list || [] ).map( function ( l ) {
            var text = strings['lost_' + l.kind + ( l.lost === 1 ? '_1' : '_n' )] || ( '%count ' + l.kind );
            return String( text ).split( '%count' ).join( String( l.lost ) );
        } ).join( ', ' );
    }

    function decode( text ) {
        return text.replace( /&(#x[0-9a-f]+|#\d+|amp|lt|gt|quot|apos|nbsp|#39);/gi, function ( all, e ) {
            var key = e.toLowerCase();
            if ( key.charAt( 0 ) === '#' ) {
                var code = key.charAt( 1 ) === 'x' ? parseInt( key.slice( 2 ), 16 ) : parseInt( key.slice( 1 ), 10 );
                return code > 0 && code < 0x110000 ? String.fromCodePoint( code ) : '';
            }
            return { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' }[key];
        } );
    }

    /**
     * The value without script and style elements, in linear time: each element ends at the first closing tag of its
     * name; an element that is never closed is left as it is (and no later one of the same name is searched again).
     */
    function stripRaw( text ) {
        var lower = text.toLowerCase(), out = '', pos = 0, re = /<(script|style)\b/gi, none = {}, m, name, close, end;
        while ( ( m = re.exec( text ) ) !== null ) {
            name = m[1].toLowerCase();
            if ( m.index < pos || none[name] ) {
                continue;
            }
            close = lower.indexOf( '</' + name, re.lastIndex );
            end = close < 0 ? -1 : lower.indexOf( '>', close );
            if ( end < 0 ) {
                none[name] = true;
                continue;
            }
            out += text.slice( pos, m.index );
            pos = end + 1;
            re.lastIndex = pos;
        }
        return out + text.slice( pos );
    }

    /** The value as plain text, blocks as paragraphs and list items as "- " lines (for a preview through renderText) */
    function toText( value ) {
        var text = String( value === undefined || value === null ? '' : value );
        // plain text ("3 < 5 and 7 > 2") stays as it is; only markup is taken apart
        if ( !/<\/?[a-zA-Z]/.test( text ) ) {
            return text;
        }
        text = stripRaw( text )
            .replace( /<br\s*\/?>/gi, '\n' )
            .replace( /<li\b[^>]*>/gi, '\n- ' )
            .replace( /<\/(p|h[1-6]|ul|ol|pre|table|tr|div|paragraph|header|section|literal|blockquote)\s*>/gi, '\n\n' )
            .replace( /<\/(td|th)\s*>/gi, ' ' )
            .replace( /<!--[\s\S]*?(?:-->|$)/g, '' )
            // [^<>]: a tag that is never closed ends at the next "<" (linear on a long answer full of "<a")
            .replace( /<\/?[a-zA-Z][^<>]*>/g, '' );
        return decode( text ).replace( /[ \t]+\n/g, '\n' ).replace( /\n{3,}/g, '\n\n' ).trim();
    }

    /** The same value, ignoring white space between tags and at the ends (editors reformat it) */
    function same( a, b ) {
        var norm = function ( v ) {
            return String( v === undefined || v === null ? '' : v ).replace( />\s+</g, '><' ).replace( /\s+/g, ' ' ).trim();
        };
        return norm( a ) === norm( b );
    }

    return { kinds: KINDS, count: count, loss: loss, describe: describe, toText: toText, same: same };
} );
