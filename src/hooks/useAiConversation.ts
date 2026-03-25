import { useCallback, useEffect, useRef, useState, type RefObject } from 'react';
import type { HistoryEntry, Message, WPItem } from '../types';
import { generateContent } from '../api';
import { applyLocalStyle, extractHtml, getLocalStyleChange } from '../util/aiDesignerHelpers';

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
  selectedHistoryIds: string[];
  isHistoryOpen: boolean;
  hasAIGenerated: boolean;
  publishTitle: string;
  chatMessagesRef: RefObject<HTMLDivElement>;
  setInput: (value: string) => void;
  setIsHistoryOpen: (value: boolean | ((prev: boolean) => boolean)) => void;
  setSelectedHistoryIds: (value: string[] | ((prev: string[]) => string[])) => void;
  setPublishTitle: (value: string) => void;
  handleSend: () => Promise<void>;
  handleConfirmRevertChanges: () => void;
  handleToggleHistorySelection: (id: string) => void;
  handleRevertSelectedHistory: () => void;
  resetAiConversation: () => void;
  appendAssistantMessage: (message: Message) => void;
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
  const [ selectedHistoryIds, setSelectedHistoryIds ] = useState<string[]>( [] );
  const [ isHistoryOpen, setIsHistoryOpen ] = useState( false );
  const [ hasAIGenerated, setHasAIGenerated ] = useState( false );
  const [ conversationId, setConversationId ] = useState<string | null>( null );
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

  const handleSend = useCallback( async () => {
    const text = input.trim();
    if ( ! text || isLoading ) {
      return;
    }

    setInput( '' );
    const userMsg: Message = { role: 'user', content: text };
    const newMessages = [ ...messages, userMsg ];
    setMessages( newMessages );

    try {
      const targetSelector = selectedBlockIndex !== null
        ? `.nfd-block-wrapper[data-block-index="${ selectedBlockIndex }"]`
        : 'body';
      console.log( 'Local style target selector:', targetSelector );
      const localStyleChange = getLocalStyleChange( text, targetSelector );
      if ( localStyleChange ) {
        const timestamp = new Date().toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
        const historyId = `${ Date.now() }-${ Math.random().toString( 16 ).slice( 2 ) }`;

        if ( selectedBlockIndex !== null && iframeRef.current?.contentDocument ) {
          const doc = iframeRef.current.contentDocument;
          const wrapper = doc.querySelector( `.nfd-block-wrapper[data-block-index="${ selectedBlockIndex }"]` ) as HTMLElement | null;
          if ( wrapper ) {
            const targets = [ wrapper, ...Array.from( wrapper.querySelectorAll<HTMLElement>( '*' ) ) ];
            if ( localStyleChange.isFontReset ) {
              targets.forEach( ( element ) => element.style.removeProperty( 'color' ) );
            } else if ( localStyleChange.colorValue ) {
              targets.forEach( ( element ) =>
                element.style.setProperty( 'color', localStyleChange.colorValue as string, 'important' )
              );
            }

            const root = doc.getElementById( 'nfd-preview-root' );
            let newHtml = '';
            if ( root ) {
              const clone = root.cloneNode( true ) as HTMLElement;
              clone.querySelectorAll( '.nfd-block-wrapper' ).forEach( ( el ) => {
                const wrapperEl = el as HTMLElement;
                wrapperEl.replaceWith( ...Array.from( wrapperEl.childNodes ) );
              } );
              newHtml = clone.innerHTML;
            }

            const nextHtml = newHtml || ( previewHtml || originalPreviewHtml || '' );
            setPreviewHtml( nextHtml );
            setHistoryEntries( ( prev ) => [
              ...prev,
              {
                id: historyId,
                html: nextHtml,
                label: `Style change: ${ localStyleChange.label }`,
                timestamp,
                publishTitle,
              },
            ] );
            setSelectedHistoryIds( [] );
            clearSelection( iframeRef );
            setHasAIGenerated( true );
            setMessages( [ ...newMessages, { role: 'assistant', content: localStyleChange.message } ] );
            return;
          }
        }

        const baseHtml = previewHtml || originalPreviewHtml || '';
        const nextHtml = applyLocalStyle( baseHtml, localStyleChange.css );
        setPreviewHtml( nextHtml );
        setHistoryEntries( ( prev ) => [
          ...prev,
          {
            id: historyId,
            html: nextHtml,
            label: `Style change: ${ localStyleChange.label }`,
            timestamp,
            publishTitle,
          },
        ] );
        setSelectedHistoryIds( [] );
        clearSelection( iframeRef );
        setHasAIGenerated( true );
        setMessages( [ ...newMessages, { role: 'assistant', content: localStyleChange.message } ] );
        return;
      }

      setIsLoading( true );
      const contextMarkup = selectedBlockHtml || previewHtml || '';
      const context = {
        current_markup: contextMarkup,
        post_id: selectedItem?.id,
        conversation_id: selectedItem ? undefined : conversationId || undefined,
      };

      const response = await generateContent( apiUrl, newMessages, context );

      let assistantContent = response?.data?.content || 'No response generated';
      const title = response?.data?.title || '';

      if ( ! selectedItem && response?.data?.conversation_id ) {
        setConversationId( response.data.conversation_id );
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
            setSelectedHistoryIds( [] );
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
    setSelectedHistoryIds( [] );
    setIsHistoryOpen( false );
    setMessages( [] );
    setInput( '' );
    clearSelection( iframeRef );
  }, [ clearSelection, iframeRef, originalPreviewHtml, setHasAIGenerated, setMessages, setPreviewHtml, setPublishTitle ] );

  const handleToggleHistorySelection = useCallback( ( id: string ) => {
    setSelectedHistoryIds( ( prev ) => (
      prev.includes( id ) ? prev.filter( ( existingId ) => existingId !== id ) : [ ...prev, id ]
    ) );
  }, [] );

  const handleRevertSelectedHistory = useCallback( () => {
    if ( selectedHistoryIds.length === 0 ) {
      return;
    }
    const selectedSet = new Set( selectedHistoryIds );
    const earliestIndex = historyEntries.findIndex( ( entry ) => selectedSet.has( entry.id ) );
    if ( earliestIndex === -1 ) {
      return;
    }
    const remainingHistory = historyEntries.slice( 0, earliestIndex );
    const nextEntry = remainingHistory[ remainingHistory.length - 1 ];
    const nextHtml = nextEntry?.html ?? originalPreviewHtml ?? null;
    const nextTitle = nextEntry?.publishTitle ?? '';

    setHistoryEntries( remainingHistory );
    setSelectedHistoryIds( [] );
    setPreviewHtml( nextHtml );
    setPublishTitle( nextTitle );
    setHasAIGenerated( remainingHistory.length > 0 );
    clearSelection( iframeRef );
  }, [
    clearSelection,
    historyEntries,
    iframeRef,
    originalPreviewHtml,
    selectedHistoryIds,
    setHasAIGenerated,
    setPreviewHtml,
    setPublishTitle,
  ] );

  const resetAiConversation = useCallback( () => {
    setMessages( [] );
    setInput( '' );
    setHistoryEntries( [] );
    setSelectedHistoryIds( [] );
    setIsHistoryOpen( false );
    setHasAIGenerated( false );
    setConversationId( null );
    clearSelection( iframeRef );
  }, [ clearSelection, iframeRef ] );

  return {
    messages,
    input,
    isLoading,
    historyEntries,
    selectedHistoryIds,
    isHistoryOpen,
    hasAIGenerated,
    publishTitle,
    chatMessagesRef,
    setInput,
    setIsHistoryOpen,
    setSelectedHistoryIds,
    setPublishTitle,
    handleSend,
    handleConfirmRevertChanges,
    handleToggleHistorySelection,
    handleRevertSelectedHistory,
    resetAiConversation,
    appendAssistantMessage,
  };
};

export default useAiConversation;
