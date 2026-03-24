import { useEffect, useRef, useState, type RefObject } from 'react';

type PreviewStylesheets = {
  blockLibrary: string;
  themeUrl: string;
  globalStyles: string;
};

type UsePreviewIframeResult = {
  iframeRef: RefObject<HTMLIFrameElement>;
};

export const usePreviewIframe = (
  previewHtml: string | null,
  siteUrl: string,
  previewStylesheets?: PreviewStylesheets
): UsePreviewIframeResult => {
  const iframeRef = useRef<HTMLIFrameElement>( null );
  const [ frontendStyles, setFrontendStyles ] = useState( '' );

  useEffect( () => {
    // Fetch frontend styles once so the preview matches the actual site.
    fetch( siteUrl )
      .then( ( res ) => res.text() )
      .then( ( html ) => {
        const parser = new DOMParser();
        const doc = parser.parseFromString( html, 'text/html' );
        // Extract all <link rel="stylesheet"> and <style> tags from the head.
        const styles = Array.from( doc.head.querySelectorAll( 'link[rel="stylesheet"], style' ) )
          .map( ( el ) => el.outerHTML )
          .join( '\n' );
        setFrontendStyles( styles );
      } )
      .catch( ( err ) => console.error( 'Failed to fetch frontend styles for preview', err ) );
  }, [ siteUrl ] );

  useEffect( () => {
    if ( iframeRef.current && previewHtml ) {
      const doc = iframeRef.current.contentDocument;
      if ( doc ) {
        const fallbackLinkTags = previewStylesheets
          ? [
              previewStylesheets.blockLibrary
                ? `<link rel="stylesheet" href="${ previewStylesheets.blockLibrary }">`
                : '',
              previewStylesheets.themeUrl
                ? `<link rel="stylesheet" href="${ previewStylesheets.themeUrl }">`
                : '',
              previewStylesheets.globalStyles
                ? `<style>${ previewStylesheets.globalStyles }</style>`
                : '',
            ].join( '\n' )
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
            document.addEventListener('DOMContentLoaded', () => {
              const root = document.getElementById('nfd-preview-root');
              if (!root) return;

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

              const containersToWrap = root.querySelectorAll('.wp-site-blocks, .entry-content, .wp-block-group, .wp-block-column, .wp-block-cover__inner-container, .wp-block-media-text__content, .wp-block-post-content');
              containersToWrap.forEach(container => {
                wrapTextNodes(container);
              });

              const wrapChildren = (parent, parentPath) => {
                Array.from(parent.children).forEach((child, index) => {
                  if (child.tagName === 'SCRIPT' || child.tagName === 'STYLE' || child.classList.contains('nfd-block-wrapper')) return;

                  const blockIndex = parentPath ? parentPath + '-' + index : index.toString();

                  if (child.classList.contains('wp-site-blocks') ||
                      child.classList.contains('entry-content') ||
                      child.classList.contains('wp-block-group') ||
                      child.classList.contains('wp-block-column') ||
                      child.classList.contains('wp-block-columns') ||
                      child.classList.contains('wp-block-cover__inner-container') ||
                      child.classList.contains('wp-block-media-text__content') ||
                      child.classList.contains('wp-block-post-content')) {

                    const originalChildCount = child.children.length;

                    if (originalChildCount > 0) {
                      wrapChildren(child, blockIndex);
                      return;
                    }
                  }

                  const style = child.getAttribute('style') || '';
                  if (child.tagName !== 'IMG' && child.tagName !== 'BR' && child.tagName !== 'HR' && child.textContent?.trim() === '' && child.children.length === 0) {
                    if (!style.includes('height') && !style.includes('width') && !child.getAttribute('height') && !child.getAttribute('width')) {
                      return;
                    }
                  }

                  if (child.parentNode.classList.contains('nfd-block-wrapper') && child.parentNode.children.length === 1) {
                    return;
                  }

                  if (child.classList.contains('nfd-block-wrapper')) return;

                  const wrapper = document.createElement('div');
                  wrapper.className = 'nfd-block-wrapper';
                  wrapper.dataset.blockIndex = blockIndex;

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
                  const tempWrapper = wrapper.cloneNode(true);
                  tempWrapper.classList.remove('nfd-block-selected');

                  tempWrapper.querySelectorAll('.nfd-block-wrapper').forEach(w => {
                    w.classList.remove('nfd-block-selected');
                  });

                  const html = tempWrapper.innerHTML;
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
        const fullHtml = previewHtml.includes( '<html' )
          ? previewHtml.replace( '</head>', `${ headStyles }${ script }</head>` ).replace( '<body', '<body id="nfd-preview-root"' )
          : `<!DOCTYPE html><html><head><meta charset="utf-8">${ headStyles }${ script }</head><body><div id="nfd-preview-root">${ previewHtml }</div></body></html>`;

        doc.write( fullHtml );
        doc.close();
      }
    }
  }, [ frontendStyles, previewHtml, previewStylesheets ] );

  return { iframeRef };
};

export default usePreviewIframe;
