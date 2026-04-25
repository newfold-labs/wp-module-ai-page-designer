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
  previewUrl: string,
  previewStylesheets?: PreviewStylesheets
): UsePreviewIframeResult => {
  const iframeRef = useRef<HTMLIFrameElement>( null );
  const [ frontendStyles, setFrontendStyles ] = useState( '' );
  const [ frontendBodyClass, setFrontendBodyClass ] = useState( '' );
  const [ frontendShellHtml, setFrontendShellHtml ] = useState( '' );

  useEffect( () => {
    // Fetch frontend styles once so the preview matches the actual site.
    fetch( previewUrl )
      .then( ( res ) => res.text() )
      .then( ( html ) => {
        const parser = new DOMParser();
        const doc = parser.parseFromString( html, 'text/html' );
        // Extract all <link rel="stylesheet"> and <style> tags from the head.
        const styles = Array.from( doc.head.querySelectorAll( 'link[rel="stylesheet"], style' ) )
          .map( ( el ) => el.outerHTML )
          .join( '\n' );
        setFrontendStyles( styles );
        const bodyClass = doc.body?.getAttribute( 'class' ) || '';
        const cleanedBodyClass = bodyClass
          .split( /\s+/ )
          .filter( ( className ) => className && className !== 'admin-bar' )
          .join( ' ' );
        setFrontendBodyClass( cleanedBodyClass );

        const selectors = [
          '.wp-site-blocks',
          '.entry-content',
          '.wp-block-post-content',
          'main',
          '#primary',
          '#content',
        ];
        const slot = doc.querySelector( selectors.join( ',' ) );
        const adminBar = doc.getElementById( 'wpadminbar' );
        if ( adminBar ) {
          adminBar.remove();
        }

        if ( slot ) {
          slot.setAttribute( 'id', 'nfd-preview-root' );
          slot.setAttribute( 'data-nfd-preview-slot', 'true' );
          slot.innerHTML = '';
          doc.querySelectorAll( 'script' ).forEach( ( script ) => script.remove() );
          setFrontendShellHtml( doc.body?.innerHTML || '' );
        } else {
          setFrontendShellHtml( '' );
        }
      } )
      .catch( ( err ) => console.error( 'Failed to fetch frontend styles for preview', err ) );
  }, [ previewUrl ] );

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

        const shouldInjectPreview = ! previewHtml.includes( '<html' );
        const safePreviewHtml = JSON.stringify( previewHtml );
        const script = `
          <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Montserrat:wght@400;600;700&family=Lora:ital,wght@0,400;0,700;1,400&family=Raleway:wght@400;600;700&display=swap">
          <style>
            body { margin: 0; padding: 0; }
            #nfd-preview-root { padding: 10px; }
            .nfd-block-wrapper { position: relative; cursor: pointer; display: flow-root; margin-bottom: 2px; }
            .nfd-block-wrapper::before { content: ''; position: absolute; inset: 0; z-index: 100; pointer-events: none; outline: 2px solid transparent; transition: all 0.2s; outline-offset: -2px; }
            .nfd-block-wrapper:hover::before { outline-color: #007cba; background: rgba(0, 124, 186, 0.05); }
            .nfd-block-wrapper.nfd-block-selected::before { outline: 3px solid #d63638; outline-offset: -3px; background: rgba(214, 54, 56, 0.05); z-index: 101; }
            .nfd-block-wrapper a, .nfd-block-wrapper button { pointer-events: none; }
            
            /* Animation keyframes */
            @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
            @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
            @keyframes bounceIn {
              0% { opacity: 0; transform: scale(0.3); }
              50% { opacity: 1; transform: scale(1.05); }
              70% { transform: scale(0.9); }
              100% { opacity: 1; transform: scale(1); }
            }
            @keyframes scaleIn { from { opacity: 0; transform: scale(0.8); } to { opacity: 1; transform: scale(1); } }
            @keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.05); } }

            /* Scoped to content area only — excludes header/footer in the shell */
            #nfd-preview-root .fade-in { animation: fadeIn 0.8s ease-out forwards; }
            #nfd-preview-root .slide-up { animation: slideUp 0.8s ease-out forwards; }
            #nfd-preview-root .bounce-in { animation: bounceIn 0.8s ease-out forwards; }
            #nfd-preview-root .scale-in { animation: scaleIn 0.8s ease-out forwards; }
            #nfd-preview-root .fade-in-delay-1 { animation: fadeIn 0.8s ease-out 0.2s forwards; opacity: 0; }
            #nfd-preview-root .fade-in-delay-2 { animation: fadeIn 0.8s ease-out 0.4s forwards; opacity: 0; }
            #nfd-preview-root .fade-in-delay-3 { animation: fadeIn 0.8s ease-out 0.6s forwards; opacity: 0; }
            #nfd-preview-root .pulse-hover { transition: all 0.3s ease; }
            #nfd-preview-root .pulse-hover:hover { animation: pulse 1s infinite; box-shadow: 0 0 20px rgba(59, 130, 246, 0.5); }
            #nfd-preview-root .glow-hover { transition: all 0.3s ease; }
            #nfd-preview-root .glow-hover:hover { box-shadow: 0 0 30px rgba(59, 130, 246, 0.6), 0 0 60px rgba(59, 130, 246, 0.4); }
            #nfd-preview-root .card-hover-lift { transition: all 0.3s ease; }
            #nfd-preview-root .card-hover-lift:hover { transform: translateY(-10px); box-shadow: 0 20px 40px rgba(0,0,0,0.15); }
            #nfd-preview-root [data-aos] { opacity: 0; transform: translateY(30px); transition: all 0.8s ease; }
            #nfd-preview-root [data-aos].aos-animate { opacity: 1; transform: translateY(0); }

            /* Safety net: reset white text outside cover blocks, then restore it inside */
            #nfd-preview-root .has-white-color { color: #1e1e1e; }
            #nfd-preview-root .wp-block-cover .has-white-color,
            #nfd-preview-root .wp-block-cover__inner-container .has-white-color { color: #fff; }
          </style>
          <script>
            document.addEventListener('DOMContentLoaded', () => {
              const root = document.getElementById('nfd-preview-root');
              if (!root) return;
              if (${ shouldInjectPreview } && ${ safePreviewHtml }) {
                root.innerHTML = ${ safePreviewHtml };
              }

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

              // Initialize scroll-triggered animations
              // threshold:0 + no rootMargin so elements visible in the iframe trigger immediately
              const animateOnScroll = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                  if (entry.isIntersecting) {
                    const delay = parseInt(entry.target.getAttribute('data-aos-delay') || '0', 10);
                    setTimeout(() => {
                      entry.target.classList.add('aos-animate');
                    }, delay);
                    animateOnScroll.unobserve(entry.target);
                  }
                });
              }, { threshold: 0 });

              document.querySelectorAll('#nfd-preview-root [data-aos]').forEach(el => {
                animateOnScroll.observe(el);
              });
            });
          </script>
        `;

        doc.open();
        const useShell = Boolean( frontendShellHtml ) && ! previewHtml.includes( '<html' );
        const fullHtml = useShell
          ? `<!DOCTYPE html><html><head><meta charset="utf-8">${ headStyles }${ script }</head><body${ frontendBodyClass ? ` class="${ frontendBodyClass }"` : '' }>${ frontendShellHtml }</body></html>`
          : previewHtml.includes( '<html' )
              ? previewHtml
                  .replace( '</head>', `${ headStyles }${ script }</head>` )
                  .replace( /<body([^>]*)>/i, ( match, attrs ) => {
                    if ( frontendBodyClass ) {
                      if ( /class=/.test( attrs ) ) {
                    const updatedAttrs = attrs.replace(
                      /class=(['"])([^'"]*)\1/i,
                      ( match: string, quote: string, classes: string ) =>
                        `class=${ quote }${ classes } ${ frontendBodyClass }${ quote }`
                    );
                        return `<body id="nfd-preview-root"${ updatedAttrs }>`;
                      }
                      return `<body id="nfd-preview-root" class="${ frontendBodyClass }"${ attrs }>`;
                    }
                    return `<body id="nfd-preview-root"${ attrs }>`;
                  } )
              : `<!DOCTYPE html><html><head><meta charset="utf-8">${ headStyles }${ script }</head><body${ frontendBodyClass ? ` class="${ frontendBodyClass }"` : '' }><div id="nfd-preview-root">${ previewHtml }</div></body></html>`;

        doc.write( fullHtml );
        doc.close();
      }
    }
  }, [ frontendBodyClass, frontendShellHtml, frontendStyles, previewHtml, previewStylesheets ] );

  return { iframeRef };
};

export default usePreviewIframe;
