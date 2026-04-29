import { useCallback, useEffect, useRef, useState, type RefObject } from 'react';
import type { HistoryEntry, Message, WPItem } from '../types';
import { generateContent, generateContentStream } from '../api';
import { extractHtml } from '../util/aiDesignerHelpers';

type UseAiConversationOptions = {
  apiUrl: string;
  previewHtml: string | null;
  originalPreviewHtml: string | null;
  publishTitle: string;
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

export const useAiConversation = ( options: UseAiConversationOptions ): UseAiConversationResult => {
  const {
    apiUrl,
    previewHtml,
    originalPreviewHtml,
    publishTitle,
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

  // Controls when the initial-load useEffect fires to populate the block registry.
  const parsedInitialRef = useRef<boolean>( false );

  const chatMessagesRef = useRef<HTMLDivElement>( null );
  const streamEnabled = ( window as any )?.nfdAIPageDesigner?.enableStreaming !== false;

  // Populate the block registry when previewHtml first becomes available (page load / selection).
  // After AI generation starts, the registry is managed manually in applyFinalResponse.
  useEffect( () => {
    if ( ! parsedInitialRef.current && previewHtml ) {
      parsedInitialRef.current = true;
      const blocks = wpBlocksParse( previewHtml );
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
    const newMessages = [ ...messages, userMsg ];
    setMessages( newMessages );

    const applyMetadataOnlyResponse = ( responseData: any ) => {
      const title = responseData?.title || '';
      const excerpt = responseData?.excerpt || '';
      const summary = responseData?.summary || '';

      const applied: string[] = [];
      if ( title && ( ! selectedItem || wantsTitle ) ) {
        setPublishTitle( title );
        setMetaTitle( title );
        applied.push( 'title' );
      }
      if ( excerpt ) {
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
      const regex = /<!--\s*(\/?)wp:([\w\/-]+)(?:\s[^-]*)?\s*(\/?)-->/gi;
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
              },
            ] );
          }
        };

        if ( isSingleBlockRequestRef.current && pendingTopLevelIndexRef.current !== null ) {
          const idx = pendingTopLevelIndexRef.current;
          let handled = false;

          // Primary: wp.blocks parse+serialize if available.
          const newBlocks = wpBlocksParse( html );
          if ( newBlocks.length > 0 && parsedBlocks.length > idx ) {
            const updatedBlocks = [ ...parsedBlocks ];
            updatedBlocks[ idx ] = newBlocks[ 0 ];
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
            if ( idx < pageBlocks.length ) {
              const updatedPageBlocks = [ ...pageBlocks ];
              updatedPageBlocks[ idx ] = html.trim();
              const newPageMarkup = updatedPageBlocks.join( '\n\n' );
              setPreviewHtml( newPageMarkup );
              addHistoryEntry( newPageMarkup );
              setLastGeneratedHtml( null );
              clearSelection( iframeRef );
            }
          }

          isSingleBlockRequestRef.current = false;
          pendingTopLevelIndexRef.current = null;
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
          const newBlocks = wpBlocksParse( html );
          if ( newBlocks.length > 0 ) {
            setParsedBlocks( newBlocks );
          }
          setPreviewHtml( html );
          addHistoryEntry( html );
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
        if ( wantsExcerpt ) {
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

      // For single-block edits: send only the selected block — no full page markup.
      // For all other cases: use existing context logic.
      const contextMarkup = isSingleBlockEdit
        ? ''
        : selectedBlockIndex !== null
          ? ( previewHtml || '' )
          : ( isFollowUpEdit ? lastGeneratedHtml : ( isMetadataRequest ? '' : previewHtml ) ) || '';

      const context: import('../api').GenerateContentContext = {
        current_markup: contextMarkup,
        post_id: selectedItem?.id,
        conversation_id: selectedItem ? undefined : conversationId || undefined,
        content_type: ( selectedItem?.type ?? 'page' ) as 'page' | 'post',
        ...( isSingleBlockEdit
          ? { selected_block_markup: selectedBlockGutenbergMarkup!, single_block_edit: true }
          : selectedBlockIndex !== null && selectedBlockHtml !== null
            ? { selected_block_markup: selectedBlockHtml }
            : {} ),
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

            // If it's a follow up edit, we need to show the stream in context
            if ( isFollowUpEdit && lastGeneratedHtml && previewHtml ) {
              setPreviewHtml( previewHtml.replace( lastGeneratedHtml, streamBuffer ) );
            } else if ( selectedBlockIndex === null ) {
              setPreviewHtml( streamBuffer );
            }
          }
          if ( event.type === 'snapshot' ) {
            streamBuffer = event.text;
            if ( isFollowUpEdit && lastGeneratedHtml && previewHtml ) {
              setPreviewHtml( previewHtml.replace( lastGeneratedHtml, streamBuffer ) );
            } else if ( selectedBlockIndex === null ) {
              setPreviewHtml( streamBuffer );
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
          setMessages( [ ...newMessages, { role: 'assistant', content: streamError } ] );
          return;
        }

        if ( ! finalData ) {
          setMessages( [ ...newMessages, { role: 'assistant', content: 'No response was generated. Please try again.' } ] );
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
        setMessages( [ ...newMessages, { role: 'assistant', content: serverMessage } ] );
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

        setMessages( [ ...newMessages, { role: 'assistant', content: 'No response was generated. Please try again.' } ] );
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
    setHasAIGenerated( true );
    clearSelection( iframeRef );
  }, [
    clearSelection,
    historyEntries,
    iframeRef,
    setHasAIGenerated,
    setPreviewHtml,
    setPublishTitle,
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
