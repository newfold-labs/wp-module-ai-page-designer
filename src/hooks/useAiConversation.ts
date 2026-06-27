import { useCallback, useEffect, useRef, useState, type RefObject } from 'react';
import type { HistoryEntry, Message, WPItem } from '../types';
import { generateContent, generateContentStream } from '../api';
import { extractHtml } from '../util/aiDesignerHelpers';

type UseAiConversationOptions = {
  apiUrl: string;
  previewHtml: string | null;
  originalPreviewHtml: string | null;
  publishTitle: string;
  metaTitle: string;
  metaExcerpt: string;
  selectedItem: WPItem | null;
  selectedBlockIndex: string | null;
  selectedBlockHtml: string | null;
  iframeRef: RefObject<HTMLIFrameElement>;
  setPreviewHtml: (value: string | null) => void;
  setPublishTitle: (value: string) => void;
  setMetaTitle: (value: string) => void;
  setMetaExcerpt: (value: string) => void;
  setMetaFeaturedImageUrl: (value: string | null) => void;
  clearSelection: (iframeRef?: RefObject<HTMLIFrameElement>) => void;
};

type UseAiConversationResult = {
  messages: Message[];
  input: string;
  isLoading: boolean;
  historyEntries: HistoryEntry[];
  isHistoryOpen: boolean;
  hasAIGenerated: boolean;
  publishTitle: string;
  chatMessagesRef: RefObject<HTMLDivElement>;
  setInput: (value: string) => void;
  setIsHistoryOpen: (value: boolean | ((prev: boolean) => boolean)) => void;
  setPublishTitle: (value: string) => void;
  handleSend: (overrideText?: string) => Promise<void>;
  handleConfirmRevertChanges: () => void;
  handleRevertToEntry: (id: string) => void;
  resetAiConversation: () => void;
  appendAssistantMessage: (message: Message) => void;
};

const REMOVAL_KEYWORDS = [ 'remove', 'delete', 'get rid of', 'take out', 'eliminate', 'cut this', 'hide this' ];
const CONTENT_QUALIFIERS = [ 'text', 'content', 'words', 'copy', 'inside', 'within', 'from this', 'heading', 'paragraph', 'image' ];

// Strict: removal of the whole block (no content-qualifier phrases present).
const isRemovalIntent = ( text: string ): boolean => {
  const lower = text.toLowerCase();
  if ( ! REMOVAL_KEYWORDS.some( ( kw ) => lower.includes( kw ) ) ) {
    return false;
  }
  if ( CONTENT_QUALIFIERS.some( ( q ) => lower.includes( q ) ) ) {
    return false;
  }
  return true;
};

// Broad: any removal keyword regardless of content qualifiers.
const hasRemovalKeyword = ( text: string ): boolean => {
  const lower = text.toLowerCase();
  return REMOVAL_KEYWORDS.some( ( kw ) => lower.includes( kw ) );
};

const isValidCssColor = ( value: string ): boolean => {
  if ( ! value ) {
    return false;
  }

  const cssApi = ( window as any )?.CSS;
  if ( cssApi?.supports ) {
    return cssApi.supports( 'color', value );
  }

  const probe = document.createElement( 'span' );
  probe.style.color = '';
  probe.style.color = value;
  return probe.style.color !== '';
};

const extractRequestedTextColor = ( text: string ): { label: string; value: string } | null => {
  const lower = text.toLowerCase();
  const isColorRequest =
    /(?:font|text)\s+color/.test( lower ) ||
    /\bchange\b.*\bcolor\b/.test( lower ) ||
    /\bmake\b.*\b(text|font)\b/.test( lower );

  if ( ! isColorRequest ) {
    return null;
  }

  const hexMatch = text.match( /#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})\b/i );
  if ( hexMatch?.[0] ) {
    return { label: hexMatch[0], value: hexMatch[0] };
  }

  const funcColorMatch = text.match( /\b(?:rgb|rgba|hsl|hsla)\([^)]+\)/i );
  if ( funcColorMatch?.[0] && isValidCssColor( funcColorMatch[0] ) ) {
    return { label: funcColorMatch[0], value: funcColorMatch[0] };
  }

  const phraseCandidates: string[] = [];
  const toMatch = text.match( /\bto\s+([^.!?,\n]+)/i );
  const colorMatch = text.match( /\bcolor\s+(?:to\s+)?([^.!?,\n]+)/i );
  if ( toMatch?.[1] ) {
    phraseCandidates.push( toMatch[1] );
  }
  if ( colorMatch?.[1] ) {
    phraseCandidates.push( colorMatch[1] );
  }

  for ( const rawPhrase of phraseCandidates ) {
    const cleaned = rawPhrase
      .replace( /\b(for|in|on|within|inside)\b.*$/i, '' )
      .replace( /[^a-zA-Z\s-]/g, ' ' )
      .replace( /\s+/g, ' ' )
      .trim();

    if ( ! cleaned ) {
      continue;
    }

    const parts = cleaned.split( ' ' );
    for ( let len = Math.min( 4, parts.length ); len >= 1; len-- ) {
      const candidate = parts.slice( 0, len ).join( ' ' ).trim();
      if ( candidate && isValidCssColor( candidate ) ) {
        return { label: candidate, value: candidate };
      }
    }
  }

  return null;
};

const extractRequestedBackgroundColor = ( text: string ): { label: string; value: string; adjustText: boolean } | null => {
  const lower = text.toLowerCase();
  // A background request is any "background"/"bg" mention that is not about an image. The
  // colour itself is resolved below; if none is found the function returns null, so phrases
  // like "remove the background" simply fall through.
  const isBackgroundRequest =
    /\bbackground\b|\bbg\b/.test( lower ) &&
    ! /\bimage|photo|picture\b/.test( lower );

  if ( ! isBackgroundRequest ) {
    return null;
  }

  const hexMatch = text.match( /#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})\b/i );
  if ( hexMatch?.[0] ) {
    return { label: hexMatch[0], value: hexMatch[0], adjustText: false };
  }

  const funcColorMatch = text.match( /\b(?:rgb|rgba|hsl|hsla)\([^)]+\)/i );
  if ( funcColorMatch?.[0] && isValidCssColor( funcColorMatch[0] ) ) {
    return { label: funcColorMatch[0], value: funcColorMatch[0], adjustText: false };
  }

  if ( /\b(darker|dark|black)\b/.test( lower ) ) {
    return { label: 'dark', value: '#1f2937', adjustText: true };
  }

  const phraseCandidates: string[] = [];
  const toMatch = text.match( /\bto\s+([^.!?,\n]+)/i );
  const backgroundMatch = text.match( /\bbackground(?:\s+color)?\s+(?:to\s+)?([^.!?,\n]+)/i );
  if ( toMatch?.[1] ) {
    phraseCandidates.push( toMatch[1] );
  }
  if ( backgroundMatch?.[1] ) {
    phraseCandidates.push( backgroundMatch[1] );
  }

  for ( const rawPhrase of phraseCandidates ) {
    const cleaned = rawPhrase
      .replace( /\b(for|in|on|within|inside)\b.*$/i, '' )
      .replace( /[^a-zA-Z\s-]/g, ' ' )
      .replace( /\s+/g, ' ' )
      .trim();

    if ( ! cleaned ) {
      continue;
    }

    const parts = cleaned.split( ' ' );
    for ( let len = Math.min( 4, parts.length ); len >= 1; len-- ) {
      const candidate = parts.slice( 0, len ).join( ' ' ).trim();
      if ( ! candidate ) {
        continue;
      }
      if ( isValidCssColor( candidate ) ) {
        return { label: candidate, value: candidate, adjustText: false };
      }
      // Multi-word CSS named colours have no space ("light blue" -> "lightblue").
      const collapsed = candidate.replace( /\s+/g, '' );
      if ( collapsed !== candidate && isValidCssColor( collapsed ) ) {
        return { label: candidate, value: collapsed, adjustText: false };
      }
    }
  }

  return null;
};

const getPreviewHtmlFromDocument = ( doc: Document ): string => {
  const root = doc.getElementById( 'nfd-preview-root' );

  if ( root ) {
    const clone = root.cloneNode( true ) as HTMLElement;

    clone.querySelectorAll( '.nfd-block-wrapper' ).forEach( ( w ) => {
      while ( w.firstChild ) {
        w.parentNode?.insertBefore( w.firstChild, w );
      }
      w.parentNode?.removeChild( w );
    } );

    clone.querySelectorAll( 'span' ).forEach( ( s ) => {
      if ( s.attributes.length === 0 ) {
        while ( s.firstChild ) {
          s.parentNode?.insertBefore( s.firstChild, s );
        }
        s.parentNode?.removeChild( s );
      }
    } );

    return clone.innerHTML;
  }

  return Array.from( doc.querySelectorAll( '.nfd-block-wrapper' ) )
    .map( ( w ) => w.innerHTML )
    .join( '\n\n' );
};

// Helper: parse Gutenberg markup into a top-level block array via wp.blocks global.
const wpBlocksParse = ( markup: string ): any[] => {
  const wp = ( window as any )?.wp;
  if ( ! wp?.blocks?.parse ) {
    return [];
  }
  try {
    return wp.blocks.parse( markup ) || [];
  } catch {
    return [];
  }
};

// Helper: serialize a block array back to Gutenberg markup via wp.blocks global.
const wpBlocksSerialize = ( blocks: any[] ): string => {
  const wp = ( window as any )?.wp;
  if ( ! wp?.blocks?.serialize ) {
    return '';
  }
  try {
    return wp.blocks.serialize( blocks ) || '';
  } catch {
    return '';
  }
};

// Split Gutenberg block markup into an array of top-level block strings.
// Does not require wp.blocks — works purely on the comment delimiter syntax.
//
// Each new top-level block start (opening OR self-closing) begins a fresh segment so
// that self-closing blocks (<!-- wp:separator /--> + <hr>) stay with their rendered
// HTML instead of leaking into the next segment and offsetting all subsequent indices.
const splitTopLevelBlocks = ( markup: string ): string[] => {
  const segments: string[][] = [ [] ];
  let segIdx = 0;
  let depth = 0;

  for ( const line of markup.split( '\n' ) ) {
    const trimmed = line.trim();
    const isSelfClosing = /^<!--\s*wp:[^ ].*?\/-->/i.test( trimmed );
    const isOpening = ! isSelfClosing && /^<!--\s*wp:/i.test( trimmed );
    const isClosing = /^<!--\s*\/wp:/i.test( trimmed );

    // Each top-level block start opens a new segment.
    if ( ( isOpening || isSelfClosing ) && depth === 0 ) {
      segments.push( [] );
      segIdx++;
    }

    segments[ segIdx ].push( line );

    if ( isOpening ) depth++;
    if ( isClosing ) depth--;
  }

  // Join each segment and drop any that contain no block comments (e.g. leading whitespace,
  // trailing fastpath cache-buster comments).
  return segments
    .map( seg => seg.join( '\n' ).trim() )
    .filter( s => s.length > 0 && /<!--\s*\/?wp:/i.test( s ) );
};

// During streaming the buffer almost always ends mid-block — a tag or a class name cut
// in half (e.g. `<div class="wp-block-colu`). Injecting that truncated tail into innerHTML
// mangles the unclosed element AND leaves partial class names that no longer match the
// theme's layout selectors (`.wp-block-columns.is-layout-flex`, `.wp-block-cover`, …), so
// the section renders unstyled — looking like "CSS is missing" when it is really just
// unmatchable markup. Return only the markup up to the last fully-closed top-level block so
// every intermediate preview is well-formed and themed; the trailing partial block appears
// once its closing delimiter arrives.
const completeBlocksPrefix = ( markup: string ): string => {
  const lines = markup.split( '\n' );
  let lastCompleteLine = -1;

  for ( let i = 0; i < lines.length; i++ ) {
    const trimmed = lines[ i ].trim();
    // Only treat a delimiter as a safe cut point if it fully arrived (ends with `-->`),
    // so we never slice through a half-written block comment.
    const isComplete = /-->$/.test( trimmed );
    const isSelfClosing = isComplete && /^<!--\s*wp:[^ ].*?\/-->/i.test( trimmed );
    const isClosing = isComplete && /^<!--\s*\/wp:/i.test( trimmed );

    // Cut at the last completed block delimiter at ANY depth — not just top-level. Inner
    // blocks that have closed are HTML-balanced; any still-open ancestor wrapper (e.g. a
    // cover or columns) already has its full opening tag + class names, so innerHTML
    // auto-closes it cleanly and the theme's layout selectors still match. This reveals
    // each inner block the moment it finishes instead of stalling until the whole
    // top-level section (often a large hero) closes.
    if ( isClosing || isSelfClosing ) {
      lastCompleteLine = i;
    }
  }

  if ( lastCompleteLine === -1 ) {
    return '';
  }
  return lines.slice( 0, lastCompleteLine + 1 ).join( '\n' );
};

// If the entire page is wrapped in a single lone wp:group (a page-wrapper pattern that
// breaks block-level indexing), hoist its children to the top level.
// This mirrors the worker's sanitizer guard 0 applied to AI-generated output, but also
// handles existing pages loaded from WordPress that were saved with this structure.
// Approximate the human-visible text length of block markup: drop block-delimiter comments,
// HTML tags and entities, then collapse whitespace. Used to detect an AI full-page response
// that would render (near-)blank so it can be rejected instead of wiping the page.
const visibleTextLength = ( markup: string ): number =>
  markup
    .replace( /<!--[\s\S]*?-->/g, ' ' )
    .replace( /<[^>]+>/g, ' ' )
    .replace( /&[a-z0-9#]+;/gi, ' ' )
    .replace( /\s+/g, ' ' )
    .trim().length;

const unwrapLonePageGroup = ( markup: string ): string => {
  const blocks = splitTopLevelBlocks( markup );
  if ( blocks.length !== 1 || ! /<!--\s*wp:group\b/i.test( blocks[ 0 ] ) ) {
    return markup;
  }

  // Keep an intentional page-background wrapper (added by applyPageBackgroundColor) — it
  // carries page styling, not just structure, so unwrapping it would drop the background.
  // The theme-colour wrapper (added by applyThemeColors) is the same case.
  if ( /nfd-page-background|nfd-page-theme/i.test( blocks[ 0 ] ) ) {
    return markup;
  }

  const outerBlock = blocks[ 0 ];
  // Find the end of the opening <!-- wp:group ... --> comment
  const firstCommentEnd = outerBlock.indexOf( '-->' );
  if ( firstCommentEnd < 0 ) return markup;

  const afterOpen = outerBlock.slice( firstCommentEnd + 3 );
  // The outermost closing tag is the LAST <!-- /wp:group --> in this block
  const lastCloseIdx = afterOpen.lastIndexOf( '<!-- /wp:group -->' );
  if ( lastCloseIdx < 0 ) return markup;

  let inner = afterOpen.slice( 0, lastCloseIdx ).trim();
  // Strip the HTML wrapper <div class="...wp-block-group..."> and its closing </div>
  inner = inner.replace( /^<div\b[^>]*\bwp-block-group\b[^>]*>\s*/i, '' );
  inner = inner.replace( /\s*<\/div>$/i, '' ).trim();

  // Only proceed if the inner content contains multiple top-level blocks;
  // a single-block inner would just recurse into the same problem.
  return splitTopLevelBlocks( inner ).length > 1 ? inner : markup;
};

// Elements that never render running text, so a colour change should skip them.
const NON_TEXT_TAGS = /^(?:IMG|SVG|PICTURE|SOURCE|VIDEO|AUDIO|CANVAS|IFRAME|EMBED|OBJECT|HR|BR|INPUT|SELECT|TEXTAREA|SCRIPT|STYLE|TEMPLATE)$/;

// Collect every element under (optionally including) `root` that directly renders text — i.e.
// has a non-whitespace direct text-node child — plus links/buttons, whose colour is set on the
// element itself. Detecting text at runtime avoids a hand-maintained tag list that keeps missing
// elements (tables, definition lists, code, figure captions, …); any current or future text tag
// is covered automatically.
const collectTextElements = ( root: Element, includeRoot: boolean ): HTMLElement[] => {
  const candidates = includeRoot
    ? [ root, ...Array.from( root.querySelectorAll( '*' ) ) ]
    : Array.from( root.querySelectorAll( '*' ) );

  const result: HTMLElement[] = [];
  for ( const el of candidates ) {
    if ( NON_TEXT_TAGS.test( el.tagName ) ) {
      continue;
    }
    const hasDirectText = Array.from( el.childNodes ).some(
      ( node ) => node.nodeType === Node.TEXT_NODE && ( node.textContent || '' ).trim() !== ''
    );
    if ( hasDirectText || 'A' === el.tagName || 'BUTTON' === el.tagName ) {
      result.push( el as HTMLElement );
    }
  }
  return result;
};

export const useAiConversation = ( options: UseAiConversationOptions ): UseAiConversationResult => {
  const {
    apiUrl,
    previewHtml,
    originalPreviewHtml,
    publishTitle,
    metaTitle,
    metaExcerpt,
    selectedItem,
    selectedBlockIndex,
    selectedBlockHtml,
    iframeRef,
    setPreviewHtml,
    setPublishTitle,
    setMetaTitle,
    setMetaExcerpt,
    setMetaFeaturedImageUrl,
    clearSelection,
  } = options;

  const [ messages, setMessages ] = useState<Message[]>( [] );
  const [ input, setInput ] = useState( '' );
  const [ isLoading, setIsLoading ] = useState( false );
  const [ historyEntries, setHistoryEntries ] = useState<HistoryEntry[]>( [] );
  const [ isHistoryOpen, setIsHistoryOpen ] = useState( false );
  const [ hasAIGenerated, setHasAIGenerated ] = useState( false );
  const [ conversationId, setConversationId ] = useState<string | null>( null );
  const [ responseId, setResponseId ] = useState<string | null>( null );

  // Block registry: top-level parsed blocks from the current full-page markup.
  // Used to serialize individual blocks for single-block edits, avoiding sending the full page.
  const [ parsedBlocks, setParsedBlocks ] = useState<any[]>( [] );

  // Track the last HTML generated by the AI so follow-up requests can
  // target it directly without needing the full page context or losing track of the edit.
  const [ lastGeneratedHtml, setLastGeneratedHtml ] = useState<string | null>( null );

  // Refs for single-block edit state that spans handleSend → applyFinalResponse.
  const isSingleBlockRequestRef = useRef<boolean>( false );
  const pendingTopLevelIndexRef = useRef<number | null>( null );
  // Additive single-block requests ("add X below this section"): direction to
  // insert when the AI returns only the new content instead of selected
  // block + new content, plus the selected block's type to tell those apart.
  const pendingInsertDirectionRef = useRef<'before' | 'after' | null>( null );
  const pendingSelectedTypeRef = useRef<string | null>( null );

  // Controls when the initial-load useEffect fires to populate the block registry.
  const parsedInitialRef = useRef<boolean>( false );

  const chatMessagesRef = useRef<HTMLDivElement>( null );
  const streamEnabled = ( window as any )?.nfdAIPageDesigner?.enableStreaming !== false;

  // Populate the block registry when previewHtml first becomes available (page load / selection).
  // After AI generation starts, the registry is managed manually in applyFinalResponse.
  useEffect( () => {
    if ( ! parsedInitialRef.current && previewHtml ) {
      parsedInitialRef.current = true;

      // Strip lone page-wrapper group before first use. Existing pages may have been
      // saved with all content nested inside one top-level wp:group, which makes every
      // section appear as a child of DOM index 0 instead of as siblings.
      const normalized = unwrapLonePageGroup( previewHtml );
      if ( normalized !== previewHtml ) {
        setPreviewHtml( normalized );
      }

      const blocks = wpBlocksParse( normalized );
      if ( blocks.length > 0 ) {
        setParsedBlocks( blocks );
      }
    }
  }, [ previewHtml ] );

  useEffect( () => {
    // If the user manually selects a new block, clear the follow-up edit tracking
    // so we don't accidentally apply a string replacement in the wrong place later.
    if ( selectedBlockIndex !== null ) {
      setLastGeneratedHtml( null );
    }
  }, [ selectedBlockIndex ] );

  useEffect( () => {
    if ( ! chatMessagesRef.current ) {
      return;
    }
    chatMessagesRef.current.scrollTop = chatMessagesRef.current.scrollHeight;
  }, [ messages, isLoading ] );

  const appendAssistantMessage = useCallback( ( message: Message ) => {
    setMessages( ( prev ) => [ ...prev, message ] );
  }, [] );

  const handleSend = useCallback( async ( overrideText?: string ) => {
    const text = ( overrideText !== undefined ? overrideText : input ).trim();
    const wantsExcerpt = /excerpt/i.test( text );
    const wantsFeaturedImage = /(featured image|feature image|featured-image|featured-img)/i.test( text );
    const wantsTitle = /(title|headline|page title|post title|rename)/i.test( text );
    if ( ! text || isLoading ) {
      return;
    }

    setInput( '' );
    const userMsg: Message = { role: 'user', content: text };
    const newMessages = [ ...messages.filter( ( m ) => ! m.isError ), userMsg ];
    setMessages( newMessages );

    const applyMetadataOnlyResponse = ( responseData: any ) => {
      const title = responseData?.title || '';
      const excerpt = responseData?.excerpt || '';
      const summary = responseData?.summary || '';

      // Metadata edits are atomic: apply ONLY the field(s) the user explicitly
      // asked for. The AI emits all three metadata lines on every response
      // (the base prompt requires it), so without this intent gate an
      // "add an excerpt" request would also overwrite the title, and vice versa.
      const applied: string[] = [];
      if ( title && wantsTitle ) {
        setPublishTitle( title );
        setMetaTitle( title );
        applied.push( 'title' );
      }
      if ( excerpt && wantsExcerpt ) {
        setMetaExcerpt( excerpt );
        applied.push( 'excerpt' );
      }

      const fallback = applied.length
        ? `Updated ${ applied.join( ' and ' ) }.`
        : 'No changes were made.';

      setMessages( [
        ...newMessages,
        {
          role: 'assistant',
          content: summary || fallback,
          summary: summary || undefined,
        },
      ] );

      if ( responseData?.response_id ) {
        setResponseId( responseData.response_id );
      }
      if ( ! selectedItem && responseData?.conversation_id ) {
        setConversationId( responseData.conversation_id );
      }

      if ( applied.length > 0 && previewHtml !== null ) {
        const timestamp = new Date().toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
        const historyId = `${ Date.now() }-${ Math.random().toString( 16 ).slice( 2 ) }`;
        const historyLabelDetail = text.length ? `: ${ text.substring( 0, 60 ) }` : '';
        const historyLabel = `Metadata edit${ historyLabelDetail }`;

        setHistoryEntries( ( prev ) => [
          ...prev,
          {
            id: historyId,
            html: previewHtml,
            label: historyLabel,
            timestamp,
            publishTitle: ( wantsTitle && title ) ? title : publishTitle,
            metaExcerpt: ( wantsExcerpt && excerpt ) ? excerpt : metaExcerpt,
          },
        ] );
      }
    };

    const applyFinalResponse = ( rawContent: string, responseData: any, isFollowUpEdit: boolean ) => {
      const responseSummary = responseData?.summary ?? '';
      const title = responseData?.title || '';
      const fallbackSummary = selectedItem ? 'Update ready.' : 'New page ready.';

      setMessages( [
        ...newMessages,
        {
          role: 'assistant',
          content: responseSummary || fallbackSummary,
          summary: responseSummary || undefined,
          code: rawContent || undefined,
        },
      ] );

      if ( ! selectedItem && responseData?.conversation_id ) {
        setConversationId( responseData.conversation_id );
      }

      if ( responseData?.response_id ) {
        setResponseId( responseData.response_id );
      }

      let finalHtml = rawContent.trim();
      finalHtml = finalHtml.replace( /<!--(?![\s\S]*?-->)[\s\S]*$/u, '' );

      const stack: string[] = [];
      // Match the optional block attributes with a non-greedy "any char up to -->" rather than
      // [^-]* — Gutenberg attributes are full of hyphens ("slide-up", "accent-4",
      // "contrast-midtone", image URLs like photo-1513…), and [^-]* stopped at the first hyphen,
      // so most opening delimiters went untracked. That made the stack wrong and appended
      // spurious closing tags below, collapsing the page structure on every edit.
      const regex = /<!--\s*(\/?)wp:([\w\/-]+)(?:\s+[\s\S]*?)?\s*(\/?)-->/gi;
      let match;

      while ( ( match = regex.exec( finalHtml ) ) !== null ) {
        const isClosing = match[1].trim() === '/';
        const blockName = match[2].trim();
        const isSelfClosing = match[3].trim() === '/';

        if ( isSelfClosing ) {
          continue;
        }

        if ( isClosing ) {
          if ( stack.length > 0 && stack[ stack.length - 1 ] === blockName ) {
            stack.pop();
          }
        } else {
          stack.push( blockName );
        }
      }

      while ( stack.length > 0 ) {
        const blockName = stack.pop();
        finalHtml += `\n<!-- /wp:${ blockName } -->`;
      }

      const html = extractHtml( finalHtml );
      if ( html ) {
        const timestamp = new Date().toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
        const historyLabelPrefix = selectedBlockIndex !== null ? 'Targeted edit' : 'Edit';
        const historyLabelDetail = text.length ? `: ${ text.substring( 0, 60 ) }` : '';
        const historyLabel = `${ historyLabelPrefix }${ historyLabelDetail }`;
        const historyId = `${ Date.now() }-${ Math.random().toString( 16 ).slice( 2 ) }`;
        const addHistoryEntry = ( htmlSnapshot: string ) => {
          if ( htmlSnapshot && htmlSnapshot !== previewHtml ) {
            setHistoryEntries( ( prev ) => [
              ...prev,
              {
                id: historyId,
                html: htmlSnapshot,
                label: historyLabel,
                timestamp,
                publishTitle: title || publishTitle,
                metaExcerpt: responseData?.excerpt || metaExcerpt,
                metaFeaturedImageUrl: responseData?.featured_image_url || undefined,
              },
            ] );
          }
        };

        if ( isSingleBlockRequestRef.current && pendingTopLevelIndexRef.current !== null ) {
          const idx = pendingTopLevelIndexRef.current;
          let handled = false;

          // For additive requests ("add X below this section") the AI should
          // return the selected block plus the new content. When it returns
          // ONLY the new content (a single block of a different type than the
          // selected one), INSERT it next to the selected block instead of
          // replacing the selected block with it.
          const insertDirection = pendingInsertDirectionRef.current;
          const selectedType = pendingSelectedTypeRef.current;
          const returnedTypeMatch = html.match( /<!--\s*wp:([a-z0-9\/-]+)/i );
          const returnedType = returnedTypeMatch ? returnedTypeMatch[ 1 ].replace( /^core\//, '' ) : null;

          // Primary: wp.blocks parse+serialize if available. Splice in ALL
          // returned top-level blocks — additive requests ("add a section
          // below this one") legitimately return the selected block plus new
          // sibling blocks, and dropping all but the first loses content.
          const newBlocks = wpBlocksParse( html );
          if ( newBlocks.length > 0 && parsedBlocks.length > idx ) {
            const shouldInsert =
              insertDirection !== null &&
              newBlocks.length === 1;
            const updatedBlocks = [ ...parsedBlocks ];
            if ( shouldInsert ) {
              updatedBlocks.splice( insertDirection === 'before' ? idx : idx + 1, 0, ...newBlocks );
            } else {
              updatedBlocks.splice( idx, 1, ...newBlocks );
            }
            const newPageMarkup = wpBlocksSerialize( updatedBlocks );
            if ( newPageMarkup ) {
              setParsedBlocks( updatedBlocks );
              setPreviewHtml( newPageMarkup );
              addHistoryEntry( newPageMarkup );
              setLastGeneratedHtml( null );
              clearSelection( iframeRef );
              handled = true;
            }
          }

          // Fallback: string-split replacement — works without wp.blocks.
          if ( ! handled && previewHtml ) {
            const pageBlocks = splitTopLevelBlocks( previewHtml );
            const returnedBlockCount = splitTopLevelBlocks( html ).length;
            if ( idx < pageBlocks.length ) {
              const shouldInsert =
                insertDirection !== null &&
                returnedBlockCount === 1;
              const updatedPageBlocks = [ ...pageBlocks ];
              if ( shouldInsert ) {
                updatedPageBlocks.splice( insertDirection === 'before' ? idx : idx + 1, 0, html.trim() );
              } else {
                updatedPageBlocks[ idx ] = html.trim();
              }
              const newPageMarkup = updatedPageBlocks.join( '\n\n' );
              setPreviewHtml( newPageMarkup );
              addHistoryEntry( newPageMarkup );
              setLastGeneratedHtml( null );
              clearSelection( iframeRef );
            }
          }

          isSingleBlockRequestRef.current = false;
          pendingTopLevelIndexRef.current = null;
          pendingInsertDirectionRef.current = null;
          pendingSelectedTypeRef.current = null;
        } else if ( selectedBlockIndex !== null && selectedBlockHtml !== null ) {
          // Try Gutenberg block-marker replacement first; fall back to DOM patch.
          const hasBlockMarkers = /<!--\s*wp:/.test( html );
          const topLevelStr = selectedBlockIndex.split( '-' )[ 0 ];
          const idx = parseInt( topLevelStr, 10 );
          let usedBlockPath = false;

          if ( hasBlockMarkers && ! isNaN( idx ) && previewHtml ) {
            const pageBlocks = splitTopLevelBlocks( previewHtml );
            if ( idx < pageBlocks.length ) {
              // Happy path: page has Gutenberg block markers, replace the block in-place.
              const updatedPageBlocks = [ ...pageBlocks ];
              updatedPageBlocks[ idx ] = html.trim();
              const newPageMarkup = updatedPageBlocks.join( '\n\n' );
              const newBlocks = wpBlocksParse( newPageMarkup );
              if ( newBlocks.length > 0 ) {
                setParsedBlocks( newBlocks );
              }
              setPreviewHtml( newPageMarkup );
              addHistoryEntry( newPageMarkup );
              setLastGeneratedHtml( null );
              clearSelection( iframeRef );
              usedBlockPath = true;
            }
          }

          if ( ! usedBlockPath ) {
            // DOM patch — covers two cases:
            //   1. AI returned raw HTML (no block markers)
            //   2. AI returned Gutenberg markup but page is rendered HTML (no block markers to split on)
            // For case 2, strip block comments so only rendered HTML is injected.
            const patchHtml = hasBlockMarkers
              ? html.replace( /<!--[\s\S]*?-->/g, '' ).trim()
              : html;

            const doc = iframeRef.current?.contentDocument;
            if ( doc ) {
              const wrapper = doc.querySelector( `.nfd-block-wrapper[data-block-index="${ selectedBlockIndex }"]` );
              if ( wrapper ) {
                wrapper.innerHTML = patchHtml;

                const root = doc.getElementById( 'nfd-preview-root' );
                let newHtml = '';

                if ( root ) {
                  const clone = root.cloneNode( true ) as HTMLElement;

                  clone.querySelectorAll( '.nfd-block-wrapper' ).forEach( ( w ) => {
                    while ( w.firstChild ) {
                      w.parentNode?.insertBefore( w.firstChild, w );
                    }
                    w.parentNode?.removeChild( w );
                  } );

                  clone.querySelectorAll( 'span' ).forEach( ( s ) => {
                    if ( s.attributes.length === 0 ) {
                      while ( s.firstChild ) {
                        s.parentNode?.insertBefore( s.firstChild, s );
                      }
                      s.parentNode?.removeChild( s );
                    }
                  } );

                  newHtml = clone.innerHTML;
                } else {
                  newHtml = Array.from( doc.querySelectorAll( '.nfd-block-wrapper' ) )
                    .map( ( w ) => w.innerHTML )
                    .join( '\n\n' );
                }

                setPreviewHtml( newHtml );
                addHistoryEntry( newHtml );
                setLastGeneratedHtml( patchHtml );
                clearSelection( iframeRef );
              }
            } else {
              setPreviewHtml( previewHtml );
              clearSelection( iframeRef );
            }
          }
        } else if ( isFollowUpEdit && lastGeneratedHtml && previewHtml ) {
          // The user made a follow-up request to a targeted edit without re-selecting.
          // Replace the previously generated block(s) with the newly generated block(s).
          const newHtml = previewHtml.replace( lastGeneratedHtml, html );
          setPreviewHtml( newHtml );
          addHistoryEntry( newHtml );
          setLastGeneratedHtml( html );
        } else {
          // Full-page update — also covers rendered HTML targeted edits where the AI returns
          // the full modified page. Also refresh the block registry.
          //
          // Normalize a lone page-wrapper group here, not just on initial load. Newly
          // generated pages bypass the parsedInitialRef load effect (it is consumed on the
          // first partial stream buffer), so without this an AI-wrapped page keeps its single
          // outer group. That collapses the block registry to length 1 and sends every
          // subsequent additive insert ("add a pricing table below this section") to the very
          // end of the page instead of beside the selected section.
          const normalizedHtml = unwrapLonePageGroup( html );

          // Guard against a partial response wiping the page. On an additive edit
          // ("add a testimonials section") with nothing selected, the model sometimes
          // returns ONLY the new section instead of the whole page. Replacing the page
          // with that fragment deletes everything else. If the response has fewer
          // top-level sections than the current page, append it instead of replacing.
          const currentTop = previewHtml ? splitTopLevelBlocks( unwrapLonePageGroup( previewHtml ) ) : [];
          const returnedTop = splitTopLevelBlocks( normalizedHtml );
          // Additive verbs only — this naturally excludes redesign/regenerate prompts.
          const isAdditive = /\b(add|insert|include|append|put|place)\b/i.test( text );
          const isAdditiveFragment =
            hasAIGenerated &&
            isAdditive &&
            currentTop.length > 1 &&
            returnedTop.length > 0 &&
            returnedTop.length < currentTop.length;

          if ( isAdditiveFragment ) {
            const mergedHtml = unwrapLonePageGroup( [ ...currentTop, ...returnedTop ].join( '\n\n' ) );
            const mergedBlocks = wpBlocksParse( mergedHtml );
            if ( mergedBlocks.length > 0 ) {
              setParsedBlocks( mergedBlocks );
            }
            setPreviewHtml( mergedHtml );
            addHistoryEntry( mergedHtml );
          } else {
            const newBlocks = wpBlocksParse( normalizedHtml );

            // Blank-output safety net: a full-page edit (e.g. "change the colors to match the
            // theme") sometimes comes back as mangled/empty markup that renders blank. If the
            // page already had real content and the response would parse to no blocks or to
            // (near-)nothing visible, reject it — keep the current preview and tell the user —
            // instead of wiping the page.
            const currentVisible = previewHtml ? visibleTextLength( previewHtml ) : 0;
            const returnedVisible = visibleTextLength( normalizedHtml );
            const wouldBlankPage =
              hasAIGenerated &&
              currentVisible > 50 &&
              ( newBlocks.length === 0 || returnedVisible < Math.max( 20, currentVisible * 0.1 ) );

            if ( wouldBlankPage ) {
              setMessages( [
                ...newMessages,
                {
                  role: 'assistant',
                  content:
                    "I couldn't apply that change cleanly — it would have emptied the page, so I kept the current version. Try rephrasing, or select a specific section to edit.",
                },
              ] );
              return;
            }

            if ( newBlocks.length > 0 ) {
              setParsedBlocks( newBlocks );
            }
            setPreviewHtml( normalizedHtml );
            addHistoryEntry( normalizedHtml );
          }
          setLastGeneratedHtml( null );
          if ( selectedBlockIndex !== null ) {
            clearSelection( iframeRef );
          }
        }
        const isFirstGeneration = ! selectedItem && ! hasAIGenerated;
        setHasAIGenerated( true );
        if ( title && ( isFirstGeneration || wantsTitle ) ) {
          setPublishTitle( title );
          setMetaTitle( title );
        }
        // Apply the excerpt on a fresh generation too (not just explicit "add an excerpt"
        // requests) so title and excerpt land together when a new page is created.
        if ( isFirstGeneration || wantsExcerpt ) {
          const excerpt = responseData?.excerpt || '';
          if ( excerpt ) {
            setMetaExcerpt( excerpt );
          }
        }
        if ( wantsFeaturedImage ) {
          const featuredImageUrl = responseData?.featured_image_url || null;
          if ( featuredImageUrl ) {
            setMetaFeaturedImageUrl( featuredImageUrl );
          }
        }
      }
    };

    try {
      setIsLoading( true );

      const applySelectedTextColor = ( color: { label: string; value: string } ): boolean => {
        const doc = iframeRef.current?.contentDocument;
        if ( ! doc ) {
          return false;
        }
        const wrapper = doc.querySelector( `.nfd-block-wrapper[data-block-index="${ selectedBlockIndex }"]` );
        if ( ! wrapper ) {
          return false;
        }

        const textTargets = collectTextElements( wrapper, false );

        if ( textTargets.length === 0 ) {
          return false;
        }

        textTargets.forEach( ( node ) => {
          node.style.setProperty( 'color', color.value, 'important' );
        } );

        const newHtml = getPreviewHtmlFromDocument( doc );

        if ( ! newHtml ) {
          return false;
        }

        const didChange = newHtml !== previewHtml;
        setPreviewHtml( newHtml );
        setHasAIGenerated( true );
        if ( didChange ) {
          const timestamp = new Date().toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
          const historyId = `${ Date.now() }-${ Math.random().toString( 16 ).slice( 2 ) }`;
          const historyLabel = `Targeted edit: ${ text.substring( 0, 60 ) }`;
          setHistoryEntries( ( prev ) => [
            ...prev,
            {
              id: historyId,
              html: newHtml,
              label: historyLabel,
              timestamp,
              publishTitle,
              metaExcerpt,
            },
          ] );
        }

        setMessages( [
          ...newMessages,
          { role: 'assistant', content: didChange ? `Updated text color to ${ color.label } for this section.` : 'No visible text color changes were applied.' },
        ] );
        clearSelection( iframeRef );
        return true;
      };

      const applySelectedBackgroundColor = ( color: { label: string; value: string; adjustText: boolean } ): boolean => {
        const doc = iframeRef.current?.contentDocument;
        if ( ! doc ) {
          return false;
        }
        const wrapper = doc.querySelector( `.nfd-block-wrapper[data-block-index="${ selectedBlockIndex }"]` );
        const target = wrapper?.firstElementChild as HTMLElement | null;
        if ( ! target ) {
          return false;
        }

        target.style.setProperty( 'background-color', color.value, 'important' );
        if ( color.adjustText ) {
          collectTextElements( target, true ).forEach( ( node ) => {
            node.style.setProperty( 'color', '#ffffff', 'important' );
          } );
        }

        const newHtml = getPreviewHtmlFromDocument( doc );
        if ( ! newHtml ) {
          return false;
        }

        const didChange = newHtml !== previewHtml;
        setPreviewHtml( newHtml );
        setHasAIGenerated( true );
        if ( didChange ) {
          const timestamp = new Date().toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
          const historyId = `${ Date.now() }-${ Math.random().toString( 16 ).slice( 2 ) }`;
          const historyLabel = `Targeted edit: ${ text.substring( 0, 60 ) }`;
          setHistoryEntries( ( prev ) => [
            ...prev,
            {
              id: historyId,
              html: newHtml,
              label: historyLabel,
              timestamp,
              publishTitle,
              metaExcerpt,
            },
          ] );
        }

        setMessages( [
          ...newMessages,
          { role: 'assistant', content: didChange ? `Updated background color to ${ color.label } for this section.` : 'No visible background changes were applied.' },
        ] );
        clearSelection( iframeRef );
        return true;
      };

      // Apply a background colour to the WHOLE page deterministically (no AI round-trip — the
      // AI tends to mangle a full page on a style edit). The page content is wrapped in a
      // single group carrying the colour; a marker class (nfd-page-background) lets us update
      // it in place on a repeat change and keeps unwrapLonePageGroup from stripping it.
      const applyPageBackgroundColor = ( color: { label: string; value: string; adjustText: boolean } ): boolean => {
        if ( ! previewHtml ) {
          return false;
        }

        const tops = splitTopLevelBlocks( previewHtml );
        const alreadyWrapped =
          tops.length === 1 && /class="[^"]*\bnfd-page-background\b/i.test( tops[ 0 ] );

        let newHtml: string;
        if ( alreadyWrapped ) {
          // Swap the colour on the existing wrapper rather than nesting another group.
          newHtml = previewHtml
            .replace( /("background"\s*:\s*")[^"]*(")/, `$1${ color.value }$2` )
            .replace( /(background-color\s*:\s*)[^;"]*/i, `$1${ color.value }` );
        } else {
          // align:full so the colour spans the whole page width behind every section,
          // not just a centred column.
          const open = `<!-- wp:group {"align":"full","style":{"color":{"background":"${ color.value }"}},"className":"nfd-page-background"} -->`;
          const div = `<div class="wp-block-group alignfull nfd-page-background has-background" style="background-color:${ color.value }">`;
          newHtml = `${ open }\n${ div }\n${ previewHtml }\n</div>\n<!-- /wp:group -->`;
        }

        if ( newHtml === previewHtml ) {
          return false;
        }

        const parsed = wpBlocksParse( newHtml );
        if ( parsed.length > 0 ) {
          setParsedBlocks( parsed );
        }
        setPreviewHtml( newHtml );
        setHasAIGenerated( true );

        const timestamp = new Date().toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
        const historyId = `${ Date.now() }-${ Math.random().toString( 16 ).slice( 2 ) }`;
        setHistoryEntries( ( prev ) => [
          ...prev,
          {
            id: historyId,
            html: newHtml,
            label: `Page background: ${ color.label }`,
            timestamp,
            publishTitle,
            metaExcerpt,
          },
        ] );

        setMessages( [
          ...newMessages,
          { role: 'assistant', content: `Updated the page background to ${ color.label }.` },
        ] );
        clearSelection( iframeRef );
        return true;
      };

      // Re-skin the WHOLE page to the active theme's palette deterministically (no AI — the
      // AI mangles a full page on a colour edit, which previously blanked the preview). We wrap
      // the page in a single group that carries the theme's background + text colour SLUGS, so
      // colours come from the theme's own CSS (true "match the theme"), not hardcoded hex. A
      // marker class (nfd-page-theme) lets unwrapLonePageGroup keep it and lets us detect a
      // repeat request. Returns false to fall through to the AI when the palette is unavailable.
      const applyThemeColors = (): boolean => {
        if ( ! previewHtml ) {
          return false;
        }
        const palette = ( window as any )?.nfdAIPageDesigner?.colorPalette as
          | Array<{ slug: string; name: string; color: string }>
          | undefined;
        if ( ! palette || palette.length === 0 ) {
          return false;
        }

        // Pick the theme's background + text slugs by common conventions, falling back to the
        // first/last swatch so any palette yields a usable pair.
        const findSlug = ( prefs: string[], fallback: string ): string => {
          for ( const pref of prefs ) {
            const hit = palette.find(
              ( s ) => s.slug?.toLowerCase() === pref || s.name?.toLowerCase() === pref
            );
            if ( hit?.slug ) {
              return hit.slug;
            }
          }
          return fallback;
        };
        const bgSlug = findSlug( [ 'base', 'background', 'white' ], palette[ 0 ]?.slug || '' );
        const textSlug = findSlug(
          [ 'contrast', 'foreground', 'text', 'black' ],
          palette[ palette.length - 1 ]?.slug || ''
        );
        if ( ! bgSlug || ! textSlug || bgSlug === textSlug ) {
          return false;
        }

        const tops = splitTopLevelBlocks( previewHtml );
        const alreadyThemed =
          tops.length === 1 && /class="[^"]*\bnfd-page-theme\b/i.test( tops[ 0 ] );
        if ( alreadyThemed ) {
          setMessages( [
            ...newMessages,
            { role: 'assistant', content: 'The page already uses the theme colors.' },
          ] );
          clearSelection( iframeRef );
          return true;
        }

        // align:full so the theme background spans the full page width. Slug-based attributes
        // (backgroundColor/textColor + has-*-color classes) keep it theme-driven, not inline hex.
        const open = `<!-- wp:group {"align":"full","backgroundColor":"${ bgSlug }","textColor":"${ textSlug }","className":"nfd-page-theme"} -->`;
        const div = `<div class="wp-block-group alignfull nfd-page-theme has-${ textSlug }-color has-text-color has-${ bgSlug }-background-color has-background">`;
        const newHtml = `${ open }\n${ div }\n${ previewHtml }\n</div>\n<!-- /wp:group -->`;

        const parsed = wpBlocksParse( newHtml );
        if ( parsed.length > 0 ) {
          setParsedBlocks( parsed );
        }
        setPreviewHtml( newHtml );
        setHasAIGenerated( true );

        const timestamp = new Date().toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
        const historyId = `${ Date.now() }-${ Math.random().toString( 16 ).slice( 2 ) }`;
        setHistoryEntries( ( prev ) => [
          ...prev,
          {
            id: historyId,
            html: newHtml,
            label: 'Matched page to theme colors',
            timestamp,
            publishTitle,
            metaExcerpt,
          },
        ] );

        setMessages( [
          ...newMessages,
          { role: 'assistant', content: 'Updated the page colors to match the theme.' },
        ] );
        clearSelection( iframeRef );
        return true;
      };

      // Shared helper: remove the selected block from the live iframe DOM and sync state.
      const removeSelectedBlock = () => {
        const doc = iframeRef.current?.contentDocument;
        if ( ! doc ) {
          return;
        }
        const wrapper = doc.querySelector( `.nfd-block-wrapper[data-block-index="${ selectedBlockIndex }"]` );
        if ( ! wrapper ) {
          return;
        }
        wrapper.remove();

        const root = doc.getElementById( 'nfd-preview-root' );
        let newHtml = '';

        if ( root ) {
          const clone = root.cloneNode( true ) as HTMLElement;

          clone.querySelectorAll( '.nfd-block-wrapper' ).forEach( ( w ) => {
            while ( w.firstChild ) {
              w.parentNode?.insertBefore( w.firstChild, w );
            }
            w.parentNode?.removeChild( w );
          } );

          clone.querySelectorAll( 'span' ).forEach( ( s ) => {
            if ( s.attributes.length === 0 ) {
              while ( s.firstChild ) {
                s.parentNode?.insertBefore( s.firstChild, s );
              }
              s.parentNode?.removeChild( s );
            }
          } );

          newHtml = clone.innerHTML;
        } else {
          newHtml = Array.from( doc.querySelectorAll( '.nfd-block-wrapper' ) )
            .map( ( w ) => w.innerHTML )
            .join( '\n\n' );
        }

        if ( newHtml ) {
          const timestamp = new Date().toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
          const historyId = `${ Date.now() }-${ Math.random().toString( 16 ).slice( 2 ) }`;
          if ( newHtml !== previewHtml ) {
            setHistoryEntries( ( prev ) => [ ...prev, {
              id: historyId,
              html: newHtml,
              label: `Removed: ${ text.substring( 0, 60 ) }`,
              timestamp,
              publishTitle,
            } ] );
          }
          setPreviewHtml( newHtml );
          setHasAIGenerated( true );
        }
      };

      // Fast path: remove selected block without an AI round-trip.
      if ( selectedBlockIndex !== null && selectedBlockHtml !== null && isRemovalIntent( text ) ) {
        removeSelectedBlock();
        setMessages( [ ...newMessages, { role: 'assistant', content: 'Section removed.' } ] );
        clearSelection( iframeRef );
        return;
      }

      if ( selectedBlockIndex !== null && selectedBlockHtml !== null ) {
        const requestedBackgroundColor = extractRequestedBackgroundColor( text );
        if ( requestedBackgroundColor && applySelectedBackgroundColor( requestedBackgroundColor ) ) {
          return;
        }

        const requestedTextColor = extractRequestedTextColor( text );
        if ( requestedTextColor && applySelectedTextColor( requestedTextColor ) ) {
          return;
        }
      }

      // No block selected: a background-colour request targets the whole page. Apply it
      // deterministically instead of sending it to the AI (which mangles full-page edits).
      if ( selectedBlockIndex === null ) {
        const requestedPageBackground = extractRequestedBackgroundColor( text );
        if ( requestedPageBackground && applyPageBackgroundColor( requestedPageBackground ) ) {
          return;
        }

        // "match the theme colors", "use the theme palette" — recolour the whole page from the
        // theme palette deterministically. Requires both a theme word and a colour/palette word
        // so it never swallows unrelated prompts. Falls through to the AI only if no palette.
        const lowerText = text.toLowerCase();
        const wantsThemeColorMatch =
          /\btheme('s)?\b/.test( lowerText ) && /\bcolou?rs?\b|\bpalette\b/.test( lowerText );
        if ( wantsThemeColorMatch && applyThemeColors() ) {
          return;
        }
      }

      // Check if this is a metadata-only request first, before follow-up edit detection.
      // Metadata requests (excerpt, title, summary) should never send page content as context.
      const isMetadataRequest = /\b(add|create|generate|write)\s+(an?\s+)?(excerpt|title|summary)\b|^(excerpt|title|summary)$/i.test(text);

      // Redesign requests generate a full new page — never treat them as targeted follow-up edits.
      const REDESIGN_KEYWORDS = [ 'redesign', 'regenerate', 'generate again', 'redo', 'remake', 'rebuild', 'start over', 'start fresh', 'from scratch', 'create new', 'make a new', 'build a new', 'try again', 'new version', 'new design' ];
      const isRedesignRequest = REDESIGN_KEYWORDS.some( ( kw ) => text.toLowerCase().includes( kw ) );

      // Detect if this is a follow-up request to a previously generated block.
      const isFollowUpEdit = !isMetadataRequest && !isRedesignRequest && selectedBlockIndex === null && lastGeneratedHtml !== null && !!previewHtml?.includes(lastGeneratedHtml);

      // Try to use the block registry for selected-block edits.
      // If wp.blocks is available and we have a populated registry, we can serialize just the
      // clicked block's Gutenberg markup and ask the AI to return only that block modified.
      // This avoids sending the full page on every targeted edit.
      let selectedBlockGutenbergMarkup: string | null = null;
      let topLevelBlockIndex: number | null = null;

      if ( selectedBlockIndex !== null && ! isMetadataRequest && previewHtml ) {
        const topLevelStr = selectedBlockIndex.split( '-' )[ 0 ];
        const idx = parseInt( topLevelStr, 10 );
        if ( ! isNaN( idx ) && idx >= 0 ) {
          // Primary: extract directly from the markup string — no wp.blocks dependency.
          const pageBlocks = splitTopLevelBlocks( previewHtml );
          if ( idx < pageBlocks.length && pageBlocks[ idx ] ) {
            selectedBlockGutenbergMarkup = pageBlocks[ idx ];
            topLevelBlockIndex = idx;
          } else if ( parsedBlocks.length > idx ) {
            // Fallback: wp.blocks registry if string-split didn't produce a result.
            const serialized = wpBlocksSerialize( [ parsedBlocks[ idx ] ] );
            if ( serialized ) {
              selectedBlockGutenbergMarkup = serialized;
              topLevelBlockIndex = idx;
            }
          }
        }
      }

      const isSingleBlockEdit = selectedBlockGutenbergMarkup !== null;
      isSingleBlockRequestRef.current = isSingleBlockEdit;
      pendingTopLevelIndexRef.current = isSingleBlockEdit ? topLevelBlockIndex : null;

      // Detect additive intent so the response can be INSERTED next to the
      // selected block when the AI returns only the new content.
      const additiveVerb = /\b(add|insert|create|put|place)\b/i.test( text );
      const positionBefore = /\b(above|before|on top of)\b/i.test( text );
      const positionAfter = /\b(below|under|underneath|beneath|after)\b/i.test( text );
      pendingInsertDirectionRef.current =
        isSingleBlockEdit && additiveVerb && ( positionBefore || positionAfter )
          ? ( positionBefore ? 'before' : 'after' )
          : null;
      const selectedTypeMatch = isSingleBlockEdit
        ? selectedBlockGutenbergMarkup!.match( /<!--\s*wp:([a-z0-9\/-]+)/i )
        : null;
      pendingSelectedTypeRef.current = selectedTypeMatch ? selectedTypeMatch[ 1 ].replace( /^core\//, '' ) : null;

      // For single-block edits: send only the selected block — no full page markup.
      // For all other cases: use existing context logic.
      const contextMarkup = isSingleBlockEdit
        ? ''
        : selectedBlockIndex !== null
          ? ( previewHtml || '' )
          : ( isFollowUpEdit ? lastGeneratedHtml : ( isMetadataRequest ? '' : previewHtml ) ) || '';

      // When the clicked selection is a single image (one <img>), capture its src so an image
      // replacement swaps only that image — not every image in the top-level block that gets
      // sent as selected_block_markup (e.g. a 3-image gallery/columns group).
      const selectedImageUrl = ( () => {
        if ( ! isSingleBlockEdit || selectedBlockHtml === null ) {
          return undefined;
        }
        const imgCount = ( selectedBlockHtml.match( /<img\b/gi ) || [] ).length;
        if ( imgCount !== 1 ) {
          return undefined;
        }
        const srcMatch = selectedBlockHtml.match( /<img[^>]+src=["']([^"']+)["']/i );
        return srcMatch ? srcMatch[ 1 ] : undefined;
      } )();

      const context: import('../api').GenerateContentContext = {
        current_markup: contextMarkup,
        post_id: selectedItem?.id,
        conversation_id: selectedItem ? undefined : conversationId || undefined,
        content_type: ( selectedItem?.type ?? 'page' ) as 'page' | 'post',
        page_title: metaTitle || undefined,
        page_excerpt: metaExcerpt || undefined,
        ...( isSingleBlockEdit
          ? { selected_block_markup: selectedBlockGutenbergMarkup!, single_block_edit: true }
          : selectedBlockIndex !== null && selectedBlockHtml !== null
            ? { selected_block_markup: selectedBlockHtml }
            : {} ),
        ...( selectedImageUrl ? { selected_image_url: selectedImageUrl } : {} ),
      };

      const shouldStream =
        streamEnabled &&
        ! selectedItem &&
        ! hasAIGenerated;

      if ( shouldStream ) {
        let streamBuffer = '';
        let finalData: any = null;
        let streamError: string | null = null;

        await generateContentStream( apiUrl, newMessages, context, ( event ) => {
          if ( event.type === 'delta' ) {
            streamBuffer += event.text;

            // Render only the complete blocks so far — never the half-arrived trailing
            // block, which would inject truncated tags/class names and break the layout.
            const safeBuffer = completeBlocksPrefix( streamBuffer );

            // If it's a follow up edit, we need to show the stream in context
            if ( isFollowUpEdit && lastGeneratedHtml && previewHtml ) {
              setPreviewHtml( previewHtml.replace( lastGeneratedHtml, safeBuffer ) );
            } else if ( selectedBlockIndex === null ) {
              setPreviewHtml( safeBuffer );
            }
          }
          if ( event.type === 'snapshot' ) {
            streamBuffer = event.text;
            const safeBuffer = completeBlocksPrefix( streamBuffer );
            if ( isFollowUpEdit && lastGeneratedHtml && previewHtml ) {
              setPreviewHtml( previewHtml.replace( lastGeneratedHtml, safeBuffer ) );
            } else if ( selectedBlockIndex === null ) {
              setPreviewHtml( safeBuffer );
            }
          }
          if ( event.type === 'result' ) {
            finalData = event.data;
            if ( finalData?.content ) {
              if ( isFollowUpEdit && lastGeneratedHtml && previewHtml ) {
                setPreviewHtml( previewHtml.replace( lastGeneratedHtml, finalData.content ) );
              } else if ( selectedBlockIndex === null ) {
                setPreviewHtml( finalData.content );
              }
            }
          }
          if ( event.type === 'error' ) {
            streamError = event.message;
          }
        } );

        if ( streamError ) {
          setMessages( [ ...newMessages, { role: 'assistant', content: streamError, isError: true } ] );
          return;
        }

        if ( ! finalData ) {
          setMessages( [ ...newMessages, { role: 'assistant', content: 'No response was generated. Please try again.', isError: true } ] );
          return;
        }

        if ( finalData.is_metadata_only || ( ! finalData.content && ( finalData.excerpt || finalData.title || finalData.summary ) ) ) {
          // Restore the original preview HTML which might have been temporarily
          // overwritten by the AI streaming metadata comments to the UI.
          if ( previewHtml !== null ) {
            setPreviewHtml( previewHtml );
          }
          applyMetadataOnlyResponse( finalData );
          return;
        }

        applyFinalResponse( finalData.content || streamBuffer, finalData, isFollowUpEdit );
        return;
      }

      const response = await generateContent( apiUrl, newMessages, context );

      const rawContent = response?.data?.content ?? '';
      const serverMessage = response?.data?.message ?? '';

      // Message-only response: fast path signalled an error (e.g. Unsplash unavailable).
      // Show the message in chat without touching the preview.
      if ( ! rawContent && serverMessage ) {
        setMessages( [ ...newMessages, { role: 'assistant', content: serverMessage, isError: true } ] );
        return;
      }

      if ( ! rawContent ) {
        // AI returned nothing for a removal-intent prompt — treat it as intentional removal.
        if ( selectedBlockIndex !== null && selectedBlockHtml !== null && hasRemovalKeyword( text ) ) {
          removeSelectedBlock();
          setMessages( [ ...newMessages, { role: 'assistant', content: 'Section removed.' } ] );
          clearSelection( iframeRef );
          return;
        }

        const data = response?.data;
        if ( data && ( data.is_metadata_only || data.excerpt || data.title || data.summary ) ) {
          applyMetadataOnlyResponse( data );
          return;
        }

        setMessages( [ ...newMessages, { role: 'assistant', content: 'No response was generated. Please try again.', isError: true } ] );
        return;
      }

      applyFinalResponse( rawContent, response?.data, isFollowUpEdit );
    } catch ( error: any ) {
      console.error( 'AI generation error:', error );
      setMessages( [
        ...newMessages,
        {
          role: 'assistant',
          content: `Error: ${ error.message || 'Failed to generate content' }`,
          isError: true,
        },
      ] );
    } finally {
      setIsLoading( false );
    }
  }, [
    apiUrl,
    clearSelection,
    iframeRef,
    input,
    isLoading,
    messages,
    originalPreviewHtml,
    parsedBlocks,
    previewHtml,
    publishTitle,
    selectedBlockHtml,
    selectedBlockIndex,
    setHasAIGenerated,
    setInput,
    setMessages,
    setMetaExcerpt,
    setMetaFeaturedImageUrl,
    setMetaTitle,
    setPreviewHtml,
    setPublishTitle,
  ] );

  const handleConfirmRevertChanges = useCallback( () => {
    parsedInitialRef.current = false;
    setParsedBlocks( [] );
    setPreviewHtml( originalPreviewHtml );
    setHasAIGenerated( false );
    setPublishTitle( '' );
    setHistoryEntries( [] );
    setIsHistoryOpen( false );
    setMessages( [] );
    setInput( '' );
    clearSelection( iframeRef );
  }, [ clearSelection, iframeRef, originalPreviewHtml, setHasAIGenerated, setMessages, setPreviewHtml, setPublishTitle ] );

  const handleRevertToEntry = useCallback( ( id: string ) => {
    const index = historyEntries.findIndex( ( entry ) => entry.id === id );
    if ( index === -1 ) {
      return;
    }
    const remainingHistory = historyEntries.slice( 0, index + 1 );
    const targetEntry = historyEntries[ index ];

    parsedInitialRef.current = false;
    setParsedBlocks( [] );
    setHistoryEntries( remainingHistory );
    setPreviewHtml( targetEntry.html );
    setPublishTitle( targetEntry.publishTitle ?? '' );
    if ( targetEntry.publishTitle !== undefined ) {
      setMetaTitle( targetEntry.publishTitle );
    }
    if ( targetEntry.metaExcerpt !== undefined ) {
      setMetaExcerpt( targetEntry.metaExcerpt );
    }
    if ( targetEntry.metaFeaturedImageUrl !== undefined ) {
      setMetaFeaturedImageUrl( targetEntry.metaFeaturedImageUrl );
    }
    setHasAIGenerated( true );
    clearSelection( iframeRef );
  }, [
    clearSelection,
    historyEntries,
    iframeRef,
    setHasAIGenerated,
    setPreviewHtml,
    setPublishTitle,
    setMetaTitle,
    setMetaExcerpt,
    setMetaFeaturedImageUrl,
  ] );

  const resetAiConversation = useCallback( () => {
    parsedInitialRef.current = false;
    setParsedBlocks( [] );
    setMessages( [] );
    setInput( '' );
    setHistoryEntries( [] );
    setIsHistoryOpen( false );
    setHasAIGenerated( false );
    setConversationId( null );
    setResponseId( null );
    clearSelection( iframeRef );
  }, [ clearSelection, iframeRef ] );

  return {
    messages,
    input,
    isLoading,
    historyEntries,
    isHistoryOpen,
    hasAIGenerated,
    publishTitle,
    chatMessagesRef,
    setInput,
    setIsHistoryOpen,
    setPublishTitle,
    handleSend,
    handleConfirmRevertChanges,
    handleRevertToEntry,
    resetAiConversation,
    appendAssistantMessage,
  };
};

export default useAiConversation;
