import React, { useState, useRef, useEffect, useCallback } from 'react';
import apiFetch from '@wordpress/api-fetch';
import {
  SparklesIcon,
  ChatBubbleLeftRightIcon,
  UserIcon,
  CpuChipIcon,
  EyeIcon,
  PlusCircleIcon,
  DocumentIcon,
  BookOpenIcon,
  ArrowPathIcon,
  HomeIcon,
  XMarkIcon,
  ArrowUpTrayIcon,
  ArrowTopRightOnSquareIcon,
  Squares2X2Icon,
  MagnifyingGlassIcon,
} from '@heroicons/react/24/outline';

declare global {
  interface Window {
    nfdAIPageDesigner: {
      apiUrl: string;
      apiRoot: string;
      nonce: string;
      siteUrl: string;
      hasAISiteGen: boolean;
      currentUserId: number;
      ajaxUrl: string;
      previewStylesheets?: {
        blockLibrary: string;
        themeUrl: string;
        globalStyles: string;
      };
    };
  }
}

const { nfdAIPageDesigner } = window;

type Message = {
  role: 'user' | 'assistant';
  content: string;
  link?: string;
};

type WPItem = {
  id: number;
  title: { rendered: string };
  content?: { rendered: string; raw?: string };
  status: string;
  link: string;
  type: string;
};

type PublishStatus = { type: 'success' | 'error'; message: string } | null;
type HistoryEntry = {
  id: string;
  html: string;
  label: string;
  timestamp: string;
  publishTitle?: string;
};

const extractHtml = (content: string): string | null => {
  if (!content || content.trim() === '') return null;
  
  // Clean up any markdown formatting the AI might have erroneously included
  let cleanContent = content.trim();
  if (cleanContent.startsWith('```html')) {
    cleanContent = cleanContent.substring(7);
    if (cleanContent.endsWith('```')) {
      cleanContent = cleanContent.substring(0, cleanContent.length - 3);
    }
  } else if (cleanContent.startsWith('```')) {
    cleanContent = cleanContent.substring(3);
    if (cleanContent.endsWith('```')) {
      cleanContent = cleanContent.substring(0, cleanContent.length - 3);
    }
  }
  
  return cleanContent.trim();
};


const App = () => {
  const [messages, setMessages] = useState<Message[]>([]);
  const [input, setInput] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [sitePages, setSitePages] = useState<WPItem[]>([]);
  const [sitePosts, setSitePosts] = useState<WPItem[]>([]);
  const [loadingSite, setLoadingSite] = useState(false);
  const [previewHtml, setPreviewHtml] = useState<string | null>(null);
  const [originalPreviewHtml, setOriginalPreviewHtml] = useState<string | null>(null);
  const [selectedItem, setSelectedItem] = useState<WPItem | null>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [view, setView] = useState<'dashboard' | 'designer'>('dashboard');
  const [showPublishModal, setShowPublishModal] = useState(false);
  const [showRevertConfirm, setShowRevertConfirm] = useState(false);
  const [publishing, setPublishing] = useState(false);
  const [publishStatus, setPublishStatus] = useState<PublishStatus>(null);
  const [hasAIGenerated, setHasAIGenerated] = useState(false);
  const [pagesExpanded, setPagesExpanded] = useState(false);
  const [postsExpanded, setPostsExpanded] = useState(false);
  const [publishTitle, setPublishTitle] = useState('');
  const [publishedUrl, setPublishedUrl] = useState<string | null>(null);
  const [selectedBlockIndex, setSelectedBlockIndex] = useState<string | null>(null);
  const [selectedBlockHtml, setSelectedBlockHtml] = useState<string | null>(null);
  const [frontendStyles, setFrontendStyles] = useState<string>('');
  const [pagesSearchQuery, setPagesSearchQuery] = useState('');
  const [postsSearchQuery, setPostsSearchQuery] = useState('');
  const [historyEntries, setHistoryEntries] = useState<HistoryEntry[]>([]);
  const [selectedHistoryIds, setSelectedHistoryIds] = useState<string[]>([]);
  const [isHistoryOpen, setIsHistoryOpen] = useState(false);
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const chatMessagesRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    // Fetch frontend styles once so the preview matches the actual site
    fetch(nfdAIPageDesigner.siteUrl)
      .then(res => res.text())
      .then(html => {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        // Extract all <link rel="stylesheet"> and <style> tags from the head
        const styles = Array.from(doc.head.querySelectorAll('link[rel="stylesheet"], style'))
          .map(el => el.outerHTML)
          .join('\n');
        setFrontendStyles(styles);
      })
      .catch(err => console.error('Failed to fetch frontend styles for preview', err));
  }, []);

  useEffect(() => {
    const handleMessage = (event: MessageEvent) => {
      if (event.data?.type === 'NFD_BLOCK_SELECTED') {
        setSelectedBlockIndex(event.data.index !== null ? event.data.index : null);
        setSelectedBlockHtml(event.data.html || null);
      }
    };
    window.addEventListener('message', handleMessage);
    return () => window.removeEventListener('message', handleMessage);
  }, []);

  useEffect(() => {
    if (!chatMessagesRef.current) return;
    chatMessagesRef.current.scrollTop = chatMessagesRef.current.scrollHeight;
  }, [messages, isLoading]);

  const applyLocalStyle = (html: string, cssText: string) => {
    const styleTag = `<style data-nfd-local-style="true">${cssText}</style>`;
    if (html.includes('data-nfd-local-style="true"')) {
      return html.replace(
        /<style data-nfd-local-style="true">[\s\S]*?<\/style>/u,
        styleTag
      );
    }
    return `${styleTag}\n${html}`;
  };

  const getLocalStyleChange = (text: string, targetSelector: string) => {
    const normalized = text.toLowerCase();
    const cssParts: string[] = [];
    const labels: string[] = [];
    let colorValue: string | null = null;
    let isFontReset = false;

    if (/\bdark mode\b|\bdark theme\b/u.test(normalized)) {
      cssParts.push('body{background:#0f1115;color:#f1f1f1;}');
      labels.push('dark mode');
    }

    if (/\blight mode\b|\blight theme\b/u.test(normalized)) {
      cssParts.push('body{background:#ffffff;color:#111111;}');
      labels.push('light mode');
    }

    const colorMap: Record<string, string> = {
      black: '#000000',
      white: '#ffffff',
      gray: '#6b7280',
      grey: '#6b7280',
      red: '#ef4444',
      orange: '#f97316',
      yellow: '#eab308',
      green: '#22c55e',
      blue: '#3b82f6',
      purple: '#a855f7',
      pink: '#ec4899',
      brown: '#92400e',
      navy: '#1e3a8a',
      teal: '#14b8a6',
    };

    const matchedColor = Object.keys(colorMap).find(color =>
      new RegExp(`\\b${color}\\b`, 'u').test(normalized)
    );

    if (/\bfont\b/u.test(normalized)) {
      if (/\b(remove|clear|reset)\b.*\bfont\b.*\bcolor\b|\bremove\b.*\bcolor\b/u.test(normalized)) {
        cssParts.push(`${targetSelector}{color:inherit !important;}`);
        labels.push('font color reset');
        isFontReset = true;
      }

      if (matchedColor) {
        cssParts.push(`${targetSelector}{color:${colorMap[matchedColor]} !important;}`);
        labels.push(`${matchedColor} font`);
        colorValue = colorMap[matchedColor];
      }
    }

    if (
      matchedColor &&
      /\b(change|set|update)\b.*\bcolor\b|\bcolor\b.*\b(change|set|update)\b/u.test(normalized) &&
      !/\bbackground\b|\bpage\b|\btheme\b|\bmode\b/u.test(normalized)
    ) {
      cssParts.push(`${targetSelector}{color:${colorMap[matchedColor]} !important;}`);
      labels.push(`${matchedColor} color`);
      colorValue = colorMap[matchedColor];
    }

    if (cssParts.length === 0) return null;

    return {
      css: cssParts.join('\n'),
      label: labels.join(' + '),
      message: 'Applied style changes to the preview.',
      colorValue,
      isFontReset,
    };
  };

  const handleItemClick = async (item: WPItem) => {
    resetAiConversation();
    setSelectedItem(item);
    setPreviewUrl(null);
    setView('designer');
    setHistoryEntries([]);
    setSelectedHistoryIds([]);
    setIsHistoryOpen(false);
    setPublishTitle('');

    const baseHtml = item.content?.raw || item.content?.rendered || '';
    setOriginalPreviewHtml(baseHtml);
    setPreviewHtml(baseHtml);
  };

  const fetchSiteContent = useCallback(async () => {
    setLoadingSite(true);
    try {
      const [pagesRes, postsRes] = await Promise.all([
        apiFetch<WPItem[]>({ path: `${nfdAIPageDesigner.apiUrl}/content/pages` }),
        apiFetch<WPItem[]>({ path: `${nfdAIPageDesigner.apiUrl}/content/posts` }),
      ]);
      setSitePages(pagesRes || []);
      setSitePosts(postsRes || []);
    } catch (error) {
      console.error('Failed to fetch site content:', error);
    } finally {
      setLoadingSite(false);
    }
  }, []);

  useEffect(() => {
    if (view === 'dashboard') {
      fetchSiteContent();
    }
  }, [fetchSiteContent, view]);

  useEffect(() => {
    if (iframeRef.current && previewHtml) {
      setPreviewUrl(null);
      const doc = iframeRef.current.contentDocument;
      if (doc) {
        const styles = nfdAIPageDesigner.previewStylesheets;
        const fallbackLinkTags = styles
          ? [
              styles.blockLibrary
                ? `<link rel="stylesheet" href="${styles.blockLibrary}">`
                : '',
              styles.themeUrl
                ? `<link rel="stylesheet" href="${styles.themeUrl}">`
                : '',
              styles.globalStyles
                ? `<style>${styles.globalStyles}</style>`
                : '',
            ].join('\n')
          : '';
          
        const headStyles = frontendStyles ? frontendStyles : fallbackLinkTags;

        const script = `
          <style>
            body { margin: 0; padding: 0; }
            #nfd-preview-root { padding: 10px; }
            .nfd-block-wrapper { position: relative; cursor: pointer; display: flow-root; margin-bottom: 2px; }
            .nfd-block-wrapper::before { content: ''; position: absolute; inset: 0; z-index: 100; pointer-events: none; outline: 2px solid transparent; transition: all 0.2s; outline-offset: -2px; }
            .nfd-block-wrapper:hover::before { outline-color: #007cba; background: rgba(0, 124, 186, 0.05); }
            .nfd-block-wrapper.nfd-block-selected::before { outline: 3px solid #d63638; outline-offset: -3px; background: rgba(214, 54, 56, 0.05); z-index: 101; }
            .nfd-block-wrapper a, .nfd-block-wrapper button { pointer-events: none; }
          </style>
          <script>
              // Helper to wrap top level elements so we can select them
            document.addEventListener('DOMContentLoaded', () => {
              const root = document.getElementById('nfd-preview-root');
              if (!root) return;
              
              // First handle text nodes that might be between elements by wrapping them
              const wrapTextNodes = (container) => {
                Array.from(container.childNodes).forEach(node => {
                  if (node.nodeType === Node.TEXT_NODE && node.textContent.trim() !== '') {
                    const span = document.createElement('span');
                    span.textContent = node.textContent;
                    container.replaceChild(span, node);
                  }
                });
              };
              
              wrapTextNodes(root);

              // Also wrap text nodes inside wp-site-blocks, entry-content, etc.
              const containersToWrap = root.querySelectorAll('.wp-site-blocks, .entry-content, .wp-block-group, .wp-block-column, .wp-block-cover__inner-container, .wp-block-media-text__content, .wp-block-post-content');
              containersToWrap.forEach(container => {
                wrapTextNodes(container);
              });

              // Now recursively wrap all elements
              const wrapChildren = (parent, parentPath) => {
                Array.from(parent.children).forEach((child, index) => {
                  if (child.tagName === 'SCRIPT' || child.tagName === 'STYLE' || child.classList.contains('nfd-block-wrapper')) return;
                  
                  const blockIndex = parentPath ? parentPath + '-' + index : index.toString();
                  
                  // If it's a container block, wrap its children instead of the container itself
                  if (child.classList.contains('wp-site-blocks') || 
                      child.classList.contains('entry-content') || 
                      child.classList.contains('wp-block-group') || 
                      child.classList.contains('wp-block-column') || 
                      child.classList.contains('wp-block-columns') ||
                      child.classList.contains('wp-block-cover__inner-container') ||
                      child.classList.contains('wp-block-media-text__content') ||
                      child.classList.contains('wp-block-post-content')
                      ) {
                    
                    const originalChildCount = child.children.length;
                    
                    if (originalChildCount > 0) {
                      wrapChildren(child, blockIndex);
                      return;
                    }
                  }

                  const style = child.getAttribute('style') || '';
                  if (child.tagName !== 'IMG' && child.tagName !== 'BR' && child.tagName !== 'HR' && child.textContent?.trim() === '' && child.children.length === 0) {
                    // One exception: if it's a spacer block or something similar that uses width/height styling to take up space, we might want to wrap it.
                    // But generally ignoring empty elements prevents invisible wrappers
                    // Check if it has height/width inline styles or attributes
                    if (!style.includes('height') && !style.includes('width') && !child.getAttribute('height') && !child.getAttribute('width')) {
                      return;
                    }
                  }

                  // Don't double wrap
                  if (child.parentNode.classList.contains('nfd-block-wrapper') && child.parentNode.children.length === 1) {
                    return;
                  }

                  // Skip wrapping if it's already a wrapper (shouldn't happen but just in case)
                  if (child.classList.contains('nfd-block-wrapper')) return;

                  const wrapper = document.createElement('div');
                  wrapper.className = 'nfd-block-wrapper';
                  wrapper.dataset.blockIndex = blockIndex;
                  
                  // Wrap it!
                  child.parentNode.insertBefore(wrapper, child);
                  wrapper.appendChild(child);
                });
              };
              
              wrapChildren(root, '');

              document.addEventListener('click', e => {
                e.preventDefault();
                const wrapper = e.target.closest('.nfd-block-wrapper');
                
                document.querySelectorAll('.nfd-block-wrapper').forEach(el => el.classList.remove('nfd-block-selected'));
                
                if (wrapper) {
                  wrapper.classList.add('nfd-block-selected');
                  // Get the inner HTML of the wrapper to send back to React
                  // Also get the outer HTML if we need to see how it's wrapped, but innerHTML is the actual block content
                  // Remove any active outlines or pseudo-elements that might leak into the saved HTML
                  const tempWrapper = wrapper.cloneNode(true);
                  tempWrapper.classList.remove('nfd-block-selected');
                  
                  // Also remove any nested wrappers just in case we are selecting a parent block
                  tempWrapper.querySelectorAll('.nfd-block-wrapper').forEach(w => {
                    w.classList.remove('nfd-block-selected');
                  });
                  
                  const html = tempWrapper.innerHTML;
                  // console.log("Selected block HTML:", html); // Debug log
                  window.parent.postMessage({ type: 'NFD_BLOCK_SELECTED', index: wrapper.dataset.blockIndex, html: html }, '*');
                } else {
                  window.parent.postMessage({ type: 'NFD_BLOCK_SELECTED', index: null, html: null }, '*');
                }
              });

              window.addEventListener('message', e => {
                if (e.data?.type === 'NFD_CLEAR_SELECTION') {
                  document.querySelectorAll('.nfd-block-wrapper').forEach(el => el.classList.remove('nfd-block-selected'));
                }
              });
            });
          </script>
        `;

        doc.open();
        // Since we are writing raw HTML strings that already have full doctype/html/body tags sometimes, 
        // we should try to inject our script/styles correctly. 
        // But for block editor output, it's usually just a list of blocks, so wrap it.
        const fullHtml = previewHtml.includes('<html') ? 
          previewHtml.replace('</head>', `${headStyles}${script}</head>`).replace('<body', '<body id="nfd-preview-root"') :
          `<!DOCTYPE html><html><head><meta charset="utf-8">${headStyles}${script}</head><body><div id="nfd-preview-root">${previewHtml}</div></body></html>`;
          
        doc.write(fullHtml);
        doc.close();
      }
    }
  }, [previewHtml]);

  const handleSend = async () => {
    const text = input.trim();
    if (!text || isLoading) return;

    setInput('');
    const userMsg: Message = { role: 'user', content: text };
    const newMessages = [...messages, userMsg];
    setMessages(newMessages);

    try {
      const targetSelector = selectedBlockIndex !== null
        ? `.nfd-block-wrapper[data-block-index="${selectedBlockIndex}"]`
        : 'body';
      console.log('Local style target selector:', targetSelector);
      const localStyleChange = getLocalStyleChange(text, targetSelector);
      if (localStyleChange) {
        const timestamp = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        const historyId = `${Date.now()}-${Math.random().toString(16).slice(2)}`;

        if (selectedBlockIndex !== null && iframeRef.current?.contentDocument) {
          const doc = iframeRef.current.contentDocument;
          const wrapper = doc.querySelector(`.nfd-block-wrapper[data-block-index="${selectedBlockIndex}"]`) as HTMLElement | null;
          if (wrapper) {
            const targets = [wrapper, ...Array.from(wrapper.querySelectorAll<HTMLElement>('*'))];
            if (localStyleChange.isFontReset) {
              targets.forEach(element => element.style.removeProperty('color'));
            } else if (localStyleChange.colorValue) {
              targets.forEach(element =>
                element.style.setProperty('color', localStyleChange.colorValue as string, 'important')
              );
            }

            const root = doc.getElementById('nfd-preview-root');
            let newHtml = '';
            if (root) {
              const clone = root.cloneNode(true) as HTMLElement;
              clone.querySelectorAll('.nfd-block-wrapper').forEach(el => {
                const wrapperEl = el as HTMLElement;
                wrapperEl.replaceWith(...Array.from(wrapperEl.childNodes));
              });
              newHtml = clone.innerHTML;
            }

            const nextHtml = newHtml || (previewHtml || originalPreviewHtml || '');
            setPreviewHtml(nextHtml);
            setHistoryEntries(prev => [
              ...prev,
              {
                id: historyId,
                html: nextHtml,
                label: `Style change: ${localStyleChange.label}`,
                timestamp,
                publishTitle,
              },
            ]);
            setSelectedHistoryIds([]);
            setSelectedBlockIndex(null);
            setSelectedBlockHtml(null);
            setHasAIGenerated(true);
            setMessages([...newMessages, { role: 'assistant', content: localStyleChange.message }]);
            return;
          }
        }

        const baseHtml = previewHtml || originalPreviewHtml || '';
        const nextHtml = applyLocalStyle(baseHtml, localStyleChange.css);
        setPreviewHtml(nextHtml);
        setHistoryEntries(prev => [
          ...prev,
          {
            id: historyId,
            html: nextHtml,
            label: `Style change: ${localStyleChange.label}`,
            timestamp,
            publishTitle,
          },
        ]);
        setSelectedHistoryIds([]);
        setSelectedBlockIndex(null);
        setSelectedBlockHtml(null);
        setHasAIGenerated(true);
        setMessages([...newMessages, { role: 'assistant', content: localStyleChange.message }]);
        return;
      }

      setIsLoading(true);
      const contextMarkup = selectedBlockHtml || previewHtml || '';

      const response = await apiFetch<{ data: { content: string; title?: string } }>({
        path: `${nfdAIPageDesigner.apiUrl}/generate`,
        method: 'POST',
        data: { 
          messages: newMessages,
          context: {
            current_markup: contextMarkup
          }
        },
      });

      let assistantContent = response?.data?.content || 'No response generated';
      let title = response?.data?.title || '';
      
      setMessages([...newMessages, { role: 'assistant', content: assistantContent }]);

      let finalHtml = assistantContent;
      finalHtml = finalHtml.trim();
      
      // Cleanup any incomplete comments that the AI might have cut off
      finalHtml = finalHtml.replace(/<!--(?![\s\S]*?-->)[\s\S]*$/u, '');
      
      // Simple stack to close any unclosed blocks
      const stack: string[] = [];
      const regex = /<!--\s*(\/?)wp:([\w\/-]+)(?:\s[^-]*)?\s*(\/?)-->/gi;
      let match;
      
      while ((match = regex.exec(finalHtml)) !== null) {
        const isClosing = match[1].trim() === '/';
        const blockName = match[2].trim();
        const isSelfClosing = match[3].trim() === '/';

        if (isSelfClosing) continue;

        if (isClosing) {
          if (stack.length > 0 && stack[stack.length - 1] === blockName) {
            stack.pop();
          }
        } else {
          stack.push(blockName);
        }
      }

      while (stack.length > 0) {
        const blockName = stack.pop();
        finalHtml += `\n<!-- /wp:${blockName} -->`;
      }

      const html = extractHtml(finalHtml);
      if (html) {
        const timestamp = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        const historyLabelPrefix = selectedBlockIndex !== null ? 'Targeted edit' : 'Edit';
        const historyLabelDetail = text.length ? `: ${text.substring(0, 60)}` : '';
        const historyLabel = `${historyLabelPrefix}${historyLabelDetail}`;
        const historyId = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
        const addHistoryEntry = (htmlSnapshot: string) => {
          if (htmlSnapshot && htmlSnapshot !== previewHtml) {
            setHistoryEntries(prev => [
              ...prev,
              {
                id: historyId,
                html: htmlSnapshot,
                label: historyLabel,
                timestamp,
                publishTitle: title || publishTitle,
              },
            ]);
            setSelectedHistoryIds([]);
          }
        };
        if (selectedBlockIndex !== null && selectedBlockHtml !== null) {
          const doc = iframeRef.current?.contentDocument;
          if (doc) {
             const wrapper = doc.querySelector(`.nfd-block-wrapper[data-block-index="${selectedBlockIndex}"]`);
             if (wrapper) {
               wrapper.innerHTML = html;
               
               // Re-unwrap everything to save back to raw HTML state
               const root = doc.getElementById('nfd-preview-root');
               let newHtml = '';
               
               if (root) {
                 // Deep clone the root so we don't mess up the iframe live DOM
                 const clone = root.cloneNode(true) as HTMLElement;
                 
                 // Find all wrappers and replace them with their contents
                 const wrappers = clone.querySelectorAll('.nfd-block-wrapper');
                 wrappers.forEach(w => {
                   while (w.firstChild) {
                     w.parentNode?.insertBefore(w.firstChild, w);
                   }
                   w.parentNode?.removeChild(w);
                 });

                 // Unwrap any spans we created for text nodes
                 const spans = clone.querySelectorAll('span');
                 spans.forEach(s => {
                    // Only unwrap if it looks like one of ours (no classes, etc)
                    if (s.attributes.length === 0) {
                        while (s.firstChild) {
                            s.parentNode?.insertBefore(s.firstChild, s);
                        }
                        s.parentNode?.removeChild(s);
                    }
                 });
                 
                 newHtml = clone.innerHTML;
               } else {
                 // Fallback if doc root is null
                 newHtml = Array.from(doc.querySelectorAll('.nfd-block-wrapper'))
                    .map(w => w.innerHTML)
                    .join('\n\n');
               }
               
               // Restore spacing/formatting a bit if possible, though Gutenberg should be fine
               setPreviewHtml(newHtml);
               addHistoryEntry(newHtml);
             }
          } else {
             // Fallback if doc is null
             setPreviewHtml(previewHtml);
          }
          setSelectedBlockIndex(null); // Reset selection after edit
          setSelectedBlockHtml(null);
        } else {
          setPreviewHtml(html);
          addHistoryEntry(html);
        }
        setHasAIGenerated(true);
        if (title) setPublishTitle(title);
      }
    } catch (error: any) {
      console.error('AI generation error:', error);
      setMessages([...newMessages, {
        role: 'assistant',
        content: `Error: ${error.message || 'Failed to generate content'}`,
      }]);
    } finally {
      setIsLoading(false);
    }
  };

  const handleRevertChanges = () => {
    if (!hasAIGenerated) return;
    setShowRevertConfirm(true);
  };

  const handleConfirmRevertChanges = () => {
    setPreviewHtml(originalPreviewHtml);
    setHasAIGenerated(false);
    setPublishTitle('');
    setHistoryEntries([]);
    setSelectedHistoryIds([]);
    setIsHistoryOpen(false);
    setSelectedBlockIndex(null);
    setSelectedBlockHtml(null);
    setMessages([]);
    setInput('');
    setShowRevertConfirm(false);
    if (iframeRef.current?.contentWindow) {
      iframeRef.current.contentWindow.postMessage({ type: 'NFD_CLEAR_SELECTION' }, '*');
    }
  };

  const handleToggleHistorySelection = (id: string) => {
    setSelectedHistoryIds(prev => (
      prev.includes(id) ? prev.filter(existingId => existingId !== id) : [...prev, id]
    ));
  };

  const handleRevertSelectedHistory = () => {
    if (selectedHistoryIds.length === 0) return;
    const selectedSet = new Set(selectedHistoryIds);
    const earliestIndex = historyEntries.findIndex(entry => selectedSet.has(entry.id));
    if (earliestIndex === -1) return;
    const remainingHistory = historyEntries.slice(0, earliestIndex);
    const nextEntry = remainingHistory[remainingHistory.length - 1];
    const nextHtml = nextEntry?.html ?? originalPreviewHtml ?? null;
    const nextTitle = nextEntry?.publishTitle ?? '';

    setHistoryEntries(remainingHistory);
    setSelectedHistoryIds([]);
    setPreviewHtml(nextHtml);
    setPublishTitle(nextTitle);
    setHasAIGenerated(remainingHistory.length > 0);
    setSelectedBlockIndex(null);
    setSelectedBlockHtml(null);
    if (iframeRef.current?.contentWindow) {
      iframeRef.current.contentWindow.postMessage({ type: 'NFD_CLEAR_SELECTION' }, '*');
    }
  };

  const resetAiConversation = () => {
    setMessages([]);
    setInput('');
    setHistoryEntries([]);
    setSelectedHistoryIds([]);
    setIsHistoryOpen(false);
    setHasAIGenerated(false);
    setSelectedBlockIndex(null);
    setSelectedBlockHtml(null);
  };

  const handlePublish = async (type: 'new_post' | 'new_page' | 'homepage') => {
    if (!previewHtml) return;
    setPublishing(true);
    setPublishStatus(null);
    setPublishedUrl(null);
    try {
      const title = publishTitle || 'AI Generated Page';
      const data = { title, content: previewHtml, status: 'publish' };
      const endpoint = type === 'new_post' ? '/wp/v2/posts' : '/wp/v2/pages';
      const result = await apiFetch<any>({ path: endpoint, method: 'POST', data });
      if (type === 'homepage' && result?.id) {
        await apiFetch({
          path: '/wp/v2/settings',
          method: 'POST',
          data: { page_on_front: result.id, show_on_front: 'page' },
        });
      }
      const url = result?.link || null;
      setPublishedUrl(url);
      setPublishStatus({ type: 'success', message: 'Published successfully!' });
      setTimeout(() => {
        setShowPublishModal(false);
        setPublishStatus(null);
        if (url) {
          setMessages(prev => [...prev, {
            role: 'assistant',
            content: `Your page has been published successfully!`,
            link: url,
          }]);
        }
      }, 1500);
    } catch (error: any) {
      setPublishStatus({ type: 'error', message: error.message || 'Failed to publish' });
    } finally {
      setPublishing(false);
    }
  };

  const handleReplaceItem = async (item: WPItem) => {
    if (!previewHtml) return;
    setPublishing(true);
    setPublishStatus(null);
    setPublishedUrl(null);
    try {
      const itemType = item.type === 'post' ? 'posts' : 'pages';
      await apiFetch<any>({
        path: `/wp/v2/${itemType}/${item.id}`,
        method: 'POST',
        data: { content: previewHtml },
      });
      const url = item.link || null;
      setPublishedUrl(url);
      setPublishStatus({ type: 'success', message: `"${item.title.rendered}" updated!` });
      setTimeout(() => {
        setShowPublishModal(false);
        setPublishStatus(null);
        if (url) {
          setMessages(prev => [...prev, {
            role: 'assistant',
            content: `"${item.title.rendered}" has been updated successfully!`,
            link: url,
          }]);
        }
      }, 1500);
    } catch (error: any) {
      setPublishStatus({ type: 'error', message: error.message || 'Failed to update' });
    } finally {
      setPublishing(false);
    }
  };

  const normalizeTitle = (title: string) =>
    title.replace(/<[^>]*>/g, '').toLowerCase();
  const normalizedPagesQuery = pagesSearchQuery.trim().toLowerCase();
  const normalizedPostsQuery = postsSearchQuery.trim().toLowerCase();
  const isSearchingPages = normalizedPagesQuery.length > 0;
  const isSearchingPosts = normalizedPostsQuery.length > 0;
  const filteredPages = normalizedPagesQuery
    ? sitePages.filter(page =>
        normalizeTitle(page.title?.rendered || '').includes(normalizedPagesQuery)
      )
    : sitePages;
  const filteredPosts = normalizedPostsQuery
    ? sitePosts.filter(post =>
        normalizeTitle(post.title?.rendered || '').includes(normalizedPostsQuery)
      )
    : sitePosts;
  const pagesBadgeText = isSearchingPages
    ? `${filteredPages.length} of ${sitePages.length}`
    : `${sitePages.length} total`;
  const postsBadgeText = isSearchingPosts
    ? `${filteredPosts.length} of ${sitePosts.length}`
    : `${sitePosts.length} total`;

  const renderDashboard = () => (
    <div className="ai-dashboard">
      <div className="ai-dashboard-actions">
        <div className="ai-dashboard-action-card" onClick={() => {
            setSelectedItem(null);
            setPreviewHtml(null);
            setMessages([]);
            setHasAIGenerated(false);
            setOriginalPreviewHtml(null);
            setPublishTitle('');
            setHistoryEntries([]);
            setSelectedHistoryIds([]);
            setIsHistoryOpen(false);
            setView('designer');
          }}>
          <div className="ai-dashboard-action-icon ai-dashboard-action-icon--primary">
            <SparklesIcon className="icon" />
          </div>
          <h3>Create New Page with AI</h3>
          <p>Design a brand new page or post from scratch</p>
        </div>
      </div>

      <div className="ai-dashboard-divider">
        <div className="ai-dashboard-divider-line"></div>
        <span>OR</span>
        <div className="ai-dashboard-divider-line"></div>
      </div>

      <p className="ai-dashboard-description">
        Select an existing page or post to enhance with AI:
      </p>

      <div className="ai-dashboard-content">
        <div className="ai-dashboard-content-card">
          <div className="ai-dashboard-content-header">
            <DocumentIcon className="icon" />
            <h3>Pages</h3>
            <div className="ai-dashboard-search ai-dashboard-search--inline ai-dashboard-search--pages">
              <MagnifyingGlassIcon className="icon" />
              <input
                type="search"
                value={pagesSearchQuery}
                onChange={(event) => setPagesSearchQuery(event.target.value)}
                placeholder="Search pages"
                aria-label="Search pages"
              />
              {pagesSearchQuery && (
                <button
                  type="button"
                  className="ai-dashboard-search-clear"
                  onClick={() => setPagesSearchQuery('')}
                  aria-label="Clear pages search"
                >
                  <XMarkIcon className="icon-sm" />
                </button>
              )}
            </div>
            <span className="ai-dashboard-badge">{pagesBadgeText}</span>
          </div>
          <ul className="ai-dashboard-list">
            {loadingSite ? (
              <li className="ai-dashboard-loading">Loading...</li>
            ) : (
              <>
                {(isSearchingPages ? filteredPages : (pagesExpanded ? filteredPages : filteredPages.slice(0, 5))).map(page => (
                  <li key={page.id} className="ai-dashboard-list-item" onClick={() => handleItemClick(page)}>
                    <DocumentIcon className="icon-sm" />
                    <span className="ai-dashboard-item-title">{page.title.rendered}</span>
                    <span className="ai-dashboard-status">{page.status}</span>
                  </li>
                ))}
                {!loadingSite && filteredPages.length === 0 && (
                  <li className="ai-dashboard-empty">No pages found.</li>
                )}
                {!isSearchingPages && filteredPages.length > 5 && (
                  <li className="ai-dashboard-more" onClick={() => setPagesExpanded(v => !v)}>
                    {pagesExpanded ? 'Show less' : `+${filteredPages.length - 5} more`}
                  </li>
                )}
              </>
            )}
          </ul>
        </div>

        <div className="ai-dashboard-content-card">
          <div className="ai-dashboard-content-header">
            <BookOpenIcon className="icon" />
            <h3>Posts</h3>
            <div className="ai-dashboard-search ai-dashboard-search--inline ai-dashboard-search--posts">
              <MagnifyingGlassIcon className="icon" />
              <input
                type="search"
                value={postsSearchQuery}
                onChange={(event) => setPostsSearchQuery(event.target.value)}
                placeholder="Search posts"
                aria-label="Search posts"
              />
              {postsSearchQuery && (
                <button
                  type="button"
                  className="ai-dashboard-search-clear"
                  onClick={() => setPostsSearchQuery('')}
                  aria-label="Clear posts search"
                >
                  <XMarkIcon className="icon-sm" />
                </button>
              )}
            </div>
            <span className="ai-dashboard-badge">{postsBadgeText}</span>
          </div>
          <ul className="ai-dashboard-list">
            {loadingSite ? (
              <li className="ai-dashboard-loading">Loading...</li>
            ) : (
              <>
                {(isSearchingPosts ? filteredPosts : (postsExpanded ? filteredPosts : filteredPosts.slice(0, 5))).map(post => (
                  <li key={post.id} className="ai-dashboard-list-item" onClick={() => handleItemClick(post)}>
                    <BookOpenIcon className="icon-sm" />
                    <span className="ai-dashboard-item-title">{post.title.rendered}</span>
                    <span className="ai-dashboard-status">{post.status}</span>
                  </li>
                ))}
                {!loadingSite && filteredPosts.length === 0 && (
                  <li className="ai-dashboard-empty">No posts found.</li>
                )}
                {!isSearchingPosts && filteredPosts.length > 5 && (
                  <li className="ai-dashboard-more" onClick={() => setPostsExpanded(v => !v)}>
                    {postsExpanded ? 'Show less' : `+${filteredPosts.length - 5} more`}
                  </li>
                )}
              </>
            )}
          </ul>
        </div>
      </div>
    </div>
  );

  const renderPublishModal = () => {
    if (!showPublishModal || selectedItem) return null;
    return (
      <div className="publish-modal-overlay" onClick={() => { setShowPublishModal(false); setPublishStatus(null); setPublishedUrl(null); }}>
        <div className="publish-modal" onClick={e => e.stopPropagation()}>
          <div className="publish-modal-header">
            <div>
              <h2>Publish Options</h2>
              <p>Choose how to publish this content to WordPress.</p>
            </div>
            <button className="publish-modal-close" onClick={() => { setShowPublishModal(false); setPublishStatus(null); setPublishedUrl(null); }}>
              <XMarkIcon className="icon" />
            </button>
          </div>

          {publishStatus && (
            <div className={`publish-status publish-status--${publishStatus.type}`}>
              {publishStatus.message}
            </div>
          )}

          <div className="publish-modal-options">
            <button className="publish-option" onClick={() => handlePublish('new_post')} disabled={publishing}>
              <div className="publish-option-icon">
                <ArrowTopRightOnSquareIcon className="icon" />
              </div>
              <div className="publish-option-text">
                <strong>Blog Post</strong>
                <span>Publish as a new blog post</span>
              </div>
            </button>
            <button className="publish-option" onClick={() => handlePublish('new_page')} disabled={publishing}>
              <div className="publish-option-icon">
                <DocumentIcon className="icon" />
              </div>
              <div className="publish-option-text">
                <strong>New Page</strong>
                <span>Publish as a standalone page</span>
              </div>
            </button>
            <button className="publish-option" onClick={() => handlePublish('homepage')} disabled={publishing}>
              <div className="publish-option-icon">
                <HomeIcon className="icon" />
              </div>
              <div className="publish-option-text">
                <strong>Set as Homepage</strong>
                <span>Create page &amp; set as site front page</span>
              </div>
            </button>
          </div>

          <div className="publish-modal-section">
            <h4 className="publish-modal-section-title">PAGES</h4>
            <ul className="publish-modal-list">
              {sitePages.map(page => (
                <li
                  key={page.id}
                  className="publish-modal-list-item"
                  onClick={() => !publishing && handleReplaceItem(page)}
                >
                  <ArrowPathIcon className="icon" />
                  <span>{page.title.rendered}</span>
                </li>
              ))}
            </ul>
          </div>

          <div className="publish-modal-section">
            <h4 className="publish-modal-section-title">POSTS</h4>
            <ul className="publish-modal-list">
              {sitePosts.map(post => (
                <li
                  key={post.id}
                  className="publish-modal-list-item"
                  onClick={() => !publishing && handleReplaceItem(post)}
                >
                  <ArrowPathIcon className="icon" />
                  <span>{post.title.rendered}</span>
                </li>
              ))}
            </ul>
          </div>
        </div>
      </div>
    );
  };

  const renderRevertConfirm = () => {
    if (!showRevertConfirm) return null;
    const message = selectedItem
      ? 'Revert AI changes and restore the original content from WordPress?'
      : 'Revert AI changes and clear the current draft?';
    return (
      <div
        className="confirm-modal-overlay"
        onClick={() => setShowRevertConfirm(false)}
      >
        <div className="confirm-modal" onClick={event => event.stopPropagation()}>
          <div className="confirm-modal-header">
            <h3>Revert AI changes?</h3>
            <button
              type="button"
              className="confirm-modal-close"
              onClick={() => setShowRevertConfirm(false)}
              aria-label="Close revert dialog"
            >
              <XMarkIcon className="icon" />
            </button>
          </div>
          <p className="confirm-modal-body">{message}</p>
          <div className="confirm-modal-actions">
            <button
              type="button"
              className="ai-history-action ai-history-action--secondary"
              onClick={() => setShowRevertConfirm(false)}
            >
              Cancel
            </button>
            <button
              type="button"
              className="ai-history-action"
              onClick={handleConfirmRevertChanges}
            >
              Revert changes
            </button>
          </div>
        </div>
      </div>
    );
  };

  const renderTabs = () => (
    <div className="ai-designer-tabs">
      <button
        className={`ai-designer-tab ${view === 'dashboard' ? 'active' : ''}`}
        onClick={() => {
          resetAiConversation();
          setSelectedItem(null);
          setPreviewHtml(null);
          setOriginalPreviewHtml(null);
          setPublishTitle('');
          setView('dashboard');
        }}
      >
        <Squares2X2Icon className="icon" />
        Dashboard
      </button>
      <button
        className={`ai-designer-tab ${view === 'designer' ? 'active' : ''}`}
        onClick={() => {
          resetAiConversation();
          setView('designer');
        }}
      >
        <SparklesIcon className="icon" />
        Designer
      </button>
    </div>
  );

  if (view === 'dashboard') {
    return (
      <div className="ai-designer-container">
        {renderTabs()}
        <div className="ai-designer-body">
          {renderDashboard()}
        </div>
      </div>
    );
  }

  return (
    <div className="ai-designer-container">
      {renderTabs()}
      <div className="ai-designer-body">
      {/* Main Content */}
      <div className="ai-designer-main">
        <div className="ai-designer-content">
          {/* Chat Panel */}
          <div className="ai-chat-panel">
            <div className="chat-header">
              <h3>AI Chat</h3>
            </div>
            <div className="chat-messages" ref={chatMessagesRef}>
              {messages.length === 0 && (
                <div className="chat-empty-state">
                  <div className="chat-empty-state-icon">
                    <ChatBubbleLeftRightIcon className="icon" />
                  </div>
                  <p>Describe the page or content you'd like to create</p>
                  <p>The AI will generate HTML you can publish directly to WordPress.</p>
                </div>
              )}
              {messages.map((msg, i) => (
                <div key={i} className={`chat-message ${msg.role}-message`}>
                  <div className="message-avatar">
                    {msg.role === 'user' ? (
                      <UserIcon className="icon" />
                    ) : (
                      <CpuChipIcon className="icon" />
                    )}
                  </div>
                  <div className="message-content">
                    <div className="message-role">{msg.role === 'user' ? 'You' : 'AI Assistant'}</div>
                    <div className="message-text">
                      {msg.content.substring(0, 500)}
                      {msg.content.length > 500 && '...'}
                    </div>
                    {msg.link && (
                      <a
                        href={msg.link}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="message-preview-link"
                      >
                        <ArrowTopRightOnSquareIcon className="icon-xs" />
                        View published page
                      </a>
                    )}
                  </div>
                </div>
              ))}
              {isLoading && (
                <div className="chat-message assistant-message">
                  <div className="message-avatar">
                    <CpuChipIcon className="icon" />
                  </div>
                  <div className="message-loading">
                    <strong>AI Assistant:</strong>
                    <span>Generating</span>
                    <div className="loading-dots">
                      <span></span>
                      <span></span>
                      <span></span>
                    </div>
                  </div>
                </div>
              )}
            </div>

            {historyEntries.length > 0 && (
              <div className="ai-history">
                <div className="ai-history-header">
                  <h4>AI edit history</h4>
                  <div className="ai-history-header-actions">
                    <button
                      type="button"
                      className="ai-history-toggle"
                      onClick={() => setIsHistoryOpen(prev => !prev)}
                    >
                      {isHistoryOpen ? 'Hide' : 'Show'}
                    </button>
                  </div>
                </div>
                {isHistoryOpen ? (
                  <>
                    <ul className="ai-history-list">
                      {historyEntries.map(entry => (
                        <li key={entry.id} className="ai-history-item">
                          <label className="ai-history-label">
                            <input
                              type="checkbox"
                              checked={selectedHistoryIds.includes(entry.id)}
                              onChange={() => handleToggleHistorySelection(entry.id)}
                            />
                            <span className="ai-history-text">
                              {entry.label}
                              <span className="ai-history-time">{entry.timestamp}</span>
                            </span>
                          </label>
                        </li>
                      ))}
                    </ul>
                    <div className="ai-history-actions">
                      <button
                        type="button"
                        className="ai-history-action"
                        disabled={selectedHistoryIds.length === 0}
                        onClick={handleRevertSelectedHistory}
                      >
                        Revert selected (and newer)
                      </button>
                      <button
                        type="button"
                        className="ai-history-action ai-history-action--secondary"
                        disabled={selectedHistoryIds.length === 0}
                        onClick={() => setSelectedHistoryIds([])}
                      >
                        Clear selection
                      </button>
                    </div>
                    <p className="ai-history-hint">
                      Reverting removes the selected edits and anything after them.
                    </p>
                  </>
                ) : (
                  <p className="ai-history-collapsed">
                    {historyEntries.length} edits saved. Click “Show” to view.
                  </p>
                )}
              </div>
            )}

            {hasAIGenerated && !isLoading && (
              <div className="publish-bar">
              <div className="publish-bar-actions">
                <button
                  className="publish-bar-button"
                  disabled={publishing}
                  onClick={() => {
                    if (selectedItem) {
                      handleReplaceItem(selectedItem);
                      return;
                    }
                    setShowPublishModal(true);
                  }}
                >
                  <ArrowUpTrayIcon className="icon" />
                  {selectedItem ? (publishing ? 'Saving...' : 'Update in WordPress') : 'Publish to WordPress'}
                </button>
                <button
                  className="publish-bar-button publish-bar-button--secondary"
                  type="button"
                  onClick={handleRevertChanges}
                >
                  <ArrowPathIcon className="icon" />
                  Revert AI changes
                </button>
              </div>
              </div>
            )}
          </div>

          {/* Preview Panel */}
          <div className="ai-preview-panel">
            <div className="preview-header">
              <h3>Preview</h3>
            </div>
            <div className="preview-body">
              {(previewHtml || selectedItem) ? (
                <iframe
                  ref={iframeRef}
                  title="Page Preview"
                  className="preview-iframe"
                />
              ) : (
                <div className="preview-empty-state">
                  <div className="preview-empty-state-icon">
                    <EyeIcon className="icon" />
                  </div>
                  <p>Live preview will appear here</p>
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Input Area */}
        <div className="chat-input-area">
          {selectedBlockIndex !== null && (
            <div className="selected-block-indicator" style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '6px 12px', background: 'rgba(214, 54, 56, 0.05)', border: '1px solid rgba(214, 54, 56, 0.2)', borderRadius: '4px', marginBottom: '8px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                <div style={{ width: '6px', height: '6px', borderRadius: '50%', background: '#d63638', flexShrink: 0 }}></div>
                <span style={{ color: '#1d2327', fontSize: '12px', lineHeight: '1.2' }}><strong>Targeted Edit.</strong> Prompt affects only the highlighted section.</span>
              </div>
              <button onClick={() => {
                setSelectedBlockIndex(null);
                setSelectedBlockHtml(null);
                if (iframeRef.current?.contentWindow) {
                  iframeRef.current.contentWindow.postMessage({ type: 'NFD_CLEAR_SELECTION' }, '*');
                }
              }} style={{ background: 'none', border: 'none', color: '#d63638', cursor: 'pointer', padding: '2px 6px', borderRadius: '4px', fontWeight: 500, fontSize: '12px' }}>Cancel</button>
            </div>
          )}
          <div className="chat-input-wrapper">
            <textarea
              value={input}
              onChange={(e) => setInput((e.target as HTMLTextAreaElement).value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                  e.preventDefault();
                  handleSend();
                }
              }}
              placeholder="Describe your design idea... (Press Enter to send, Shift+Enter for new line)"
              className="chat-textarea"
            />
            <button
              onClick={handleSend}
              disabled={!input.trim() || isLoading}
              className="chat-send-button"
            >
              {isLoading ? 'Generating...' : 'Send'}
            </button>
          </div>
        </div>
      </div>
      </div>

      {renderRevertConfirm()}
      {renderPublishModal()}
    </div>
  );
};

export default App;
