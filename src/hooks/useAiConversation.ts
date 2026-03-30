import { useCallback, useEffect, useRef, useState, type RefObject } from 'react';
import type { HistoryEntry, Message, WPItem } from '../types';
import { generateContent } from '../api';
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

const isRemovalIntent = ( text: string ): boolean => {
  const lower = text.toLowerCase();
  if ( ! REMOVAL_KEYWORDS.some( ( kw ) => lower.includes( kw ) ) ) {
    return false;
  }
  // If the prompt refers to content *inside* the block, let the AI handle it.
  if ( CONTENT_QUALIFIERS.some( ( q ) => lower.includes( q ) ) ) {
    return false;
  }
  return true;
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
  const chatMessagesRef = useRef<HTMLDivElement>( null );

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
    if ( ! text || isLoading ) {
      return;
    }

    setInput( '' );
    const userMsg: Message = { role: 'user', content: text };
    const newMessages = [ ...messages, userMsg ];
    setMessages( newMessages );

    try {
      setIsLoading( true );

      // Fast path: remove selected block without an AI round-trip.
      if ( selectedBlockIndex !== null && selectedBlockHtml !== null && isRemovalIntent( text ) ) {
        const doc = iframeRef.current?.contentDocument;
        if ( doc ) {
          const wrapper = doc.querySelector( `.nfd-block-wrapper[data-block-index="${ selectedBlockIndex }"]` );
          if ( wrapper ) {
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
          }
        }
        setMessages( [ ...newMessages, { role: 'assistant', content: 'Section removed.' } ] );
        clearSelection( iframeRef );
        return;
      }

      const contextMarkup = selectedBlockHtml || previewHtml || '';
      const context = {
        current_markup: contextMarkup,
        post_id: selectedItem?.id,
        conversation_id: selectedItem ? undefined : conversationId || undefined,
        content_type: ( selectedItem?.type ?? 'page' ) as 'page' | 'post',
      };

      const response = await generateContent( apiUrl, newMessages, context );

      const rawContent = response?.data?.content ?? '';
      const serverMessage = response?.data?.message ?? '';

      // Message-only response: fast path signalled an error (e.g. Unsplash unavailable).
      // Show the message in chat without touching the preview.
      if ( ! rawContent && serverMessage ) {
        setMessages( [ ...newMessages, { role: 'assistant', content: serverMessage } ] );
        return;
      }

      let assistantContent = rawContent || 'No response generated';
      const title = response?.data?.title || '';

      if ( ! selectedItem && response?.data?.conversation_id ) {
        setConversationId( response.data.conversation_id );
      }

      if ( response?.data?.response_id ) {
        setResponseId( response.data.response_id );
      }

      setMessages( [ ...newMessages, { role: 'assistant', content: assistantContent } ] );

      let finalHtml = assistantContent.trim();
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

        if ( selectedBlockIndex !== null && selectedBlockHtml !== null ) {
          const doc = iframeRef.current?.contentDocument;
          if ( doc ) {
            const wrapper = doc.querySelector( `.nfd-block-wrapper[data-block-index="${ selectedBlockIndex }"]` );
            if ( wrapper ) {
              wrapper.innerHTML = html;

              const root = doc.getElementById( 'nfd-preview-root' );
              let newHtml = '';

              if ( root ) {
                const clone = root.cloneNode( true ) as HTMLElement;

                const wrappers = clone.querySelectorAll( '.nfd-block-wrapper' );
                wrappers.forEach( ( w ) => {
                  while ( w.firstChild ) {
                    w.parentNode?.insertBefore( w.firstChild, w );
                  }
                  w.parentNode?.removeChild( w );
                } );

                const spans = clone.querySelectorAll( 'span' );
                spans.forEach( ( s ) => {
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
            }
          } else {
            setPreviewHtml( previewHtml );
          }
          clearSelection( iframeRef );
        } else {
          setPreviewHtml( html );
          addHistoryEntry( html );
        }
        setHasAIGenerated( true );
        if ( title ) {
          setPublishTitle( title );
        }
      }
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
    previewHtml,
    publishTitle,
    selectedBlockHtml,
    selectedBlockIndex,
    setHasAIGenerated,
    setInput,
    setMessages,
    setPreviewHtml,
    setPublishTitle,
  ] );

  const handleConfirmRevertChanges = useCallback( () => {
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
