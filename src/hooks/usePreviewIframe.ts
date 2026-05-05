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

  // True once the current srcdoc has finished loading and nfdSetContent is callable.
  const iframeReadyRef = useRef( false );
  // Holds the latest previewHtml so the onLoad handler can inject it after shell init.
  const pendingHtmlRef = useRef<string | null>( null );

  useEffect( () => {
    // Fetch frontend styles once so the preview matches the actual site.
    fetch( previewUrl )
      .then( ( res ) => res.text() )
      .then( ( html ) => {
        const parser = new DOMParser();
        const doc = parser.parseFromString( html, 'text/html' );
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

  // Effect 1: Build the iframe shell (styles + event wiring).
  // previewHtml is intentionally excluded from deps — content is injected in-place
  // by Effect 2 via window.nfdSetContent to avoid full iframe reloads on every delta.
  useEffect( () => {
    if ( ! iframeRef.current ) {
      return;
    }

    iframeReadyRef.current = false;

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

      // The script exposes window.nfdSetContent(html) for in-place content updates.
      // Content is NOT embedded here; Effect 2 calls nfdSetContent after load.
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

          /* Suppress entrance animations while streaming — they play once on final content */
          #nfd-preview-root[data-nfd-streaming] .fade-in,
          #nfd-preview-root[data-nfd-streaming] .slide-up,
          #nfd-preview-root[data-nfd-streaming] .bounce-in,
          #nfd-preview-root[data-nfd-streaming] .scale-in,
          #nfd-preview-root[data-nfd-streaming] .fade-in-delay-1,
          #nfd-preview-root[data-nfd-streaming] .fade-in-delay-2,
          #nfd-preview-root[data-nfd-streaming] .fade-in-delay-3,
          #nfd-preview-root[data-nfd-streaming] [data-aos] {
            animation: none !important;
            opacity: 1 !important;
            transform: none !important;
            transition: none !important;
          }

          /* Safety net: reset accidental white text; restore it inside any block that has an explicit background */
          #nfd-preview-root .has-white-color { color: #1e1e1e; }
          #nfd-preview-root .wp-block-cover .has-white-color,
          #nfd-preview-root .wp-block-cover__inner-container .has-white-color,
          #nfd-preview-root .has-background .has-white-color,
          #nfd-preview-root [class*="-background-color"] .has-white-color,
          #nfd-preview-root [style*="background-color"] .has-white-color,
          #nfd-preview-root [style*="background:"] .has-white-color { color: #fff; }
          /* Extended safety net: inline white styles override class rules, so !important is required */
          #nfd-preview-root [style*="color:white"],
          #nfd-preview-root [style*="color: white"],
          #nfd-preview-root [style*="color:#fff"],
          #nfd-preview-root [style*="color: #fff"],
          #nfd-preview-root [style*="color:#ffffff"],
          #nfd-preview-root [style*="color: #ffffff"] { color: #1e1e1e !important; }
          #nfd-preview-root .wp-block-cover [style*="color:white"],
          #nfd-preview-root .wp-block-cover [style*="color: white"],
          #nfd-preview-root .wp-block-cover [style*="color:#fff"],
          #nfd-preview-root .wp-block-cover [style*="color: #fff"],
          #nfd-preview-root .wp-block-cover [style*="color:#ffffff"],
          #nfd-preview-root .wp-block-cover [style*="color: #ffffff"],
          #nfd-preview-root .has-background [style*="color:white"],
          #nfd-preview-root .has-background [style*="color: white"],
          #nfd-preview-root .has-background [style*="color:#fff"],
          #nfd-preview-root .has-background [style*="color: #fff"],
          #nfd-preview-root .has-background [style*="color:#ffffff"],
          #nfd-preview-root .has-background [style*="color: #ffffff"],
          #nfd-preview-root [class*="-background-color"] [style*="color:white"],
          #nfd-preview-root [class*="-background-color"] [style*="color: white"],
          #nfd-preview-root [class*="-background-color"] [style*="color:#fff"],
          #nfd-preview-root [class*="-background-color"] [style*="color: #fff"],
          #nfd-preview-root [class*="-background-color"] [style*="color:#ffffff"],
          #nfd-preview-root [class*="-background-color"] [style*="color: #ffffff"],
          #nfd-preview-root [style*="background-color"] [style*="color:white"],
          #nfd-preview-root [style*="background-color"] [style*="color: white"],
          #nfd-preview-root [style*="background-color"] [style*="color:#fff"],
          #nfd-preview-root [style*="background-color"] [style*="color: #fff"],
          #nfd-preview-root [style*="background-color"] [style*="color:#ffffff"],
          #nfd-preview-root [style*="background-color"] [style*="color: #ffffff"],
          #nfd-preview-root [style*="background:"] [style*="color:white"],
          #nfd-preview-root [style*="background:"] [style*="color: white"],
          #nfd-preview-root [style*="background:"] [style*="color:#fff"],
          #nfd-preview-root [style*="background:"] [style*="color: #fff"],
          #nfd-preview-root [style*="background:"] [style*="color:#ffffff"],
          #nfd-preview-root [style*="background:"] [style*="color: #ffffff"] { color: #fff !important; }
        </style>
        <script>
          // Module-level so nfdSetContent can re-run them on each content update.
          var _animObserver = null;
          var _streamingTimer = null;
          var _lastHtml = '';

          function _wrapTextNodes(container) {
            Array.from(container.childNodes).forEach(function(node) {
              if (node.nodeType === Node.TEXT_NODE && node.textContent.trim() !== '') {
                var span = document.createElement('span');
                span.textContent = node.textContent;
                container.replaceChild(span, node);
              }
            });
          }

          function _wrapChildren(parent, parentPath) {
            Array.from(parent.children).forEach(function(child, index) {
              if (child.tagName === 'SCRIPT' || child.tagName === 'STYLE' || child.classList.contains('nfd-block-wrapper')) return;

              var blockIndex = parentPath ? parentPath + '-' + index : index.toString();

              if (child.classList.contains('wp-site-blocks') ||
                  child.classList.contains('entry-content') ||
                  child.classList.contains('wp-block-group') ||
                  child.classList.contains('wp-block-column') ||
                  child.classList.contains('wp-block-columns') ||
                  child.classList.contains('wp-block-cover__inner-container') ||
                  child.classList.contains('wp-block-media-text__content') ||
                  child.classList.contains('wp-block-post-content')) {

                if (child.children.length > 0) {
                  _wrapChildren(child, blockIndex);
                  return;
                }
              }

              var style = child.getAttribute('style') || '';
              if (child.tagName !== 'IMG' && child.tagName !== 'BR' && child.tagName !== 'HR' && child.textContent && child.textContent.trim() === '' && child.children.length === 0) {
                if (!style.includes('height') && !style.includes('width') && !child.getAttribute('height') && !child.getAttribute('width')) {
                  return;
                }
              }

              if (child.parentNode.classList.contains('nfd-block-wrapper') && child.parentNode.children.length === 1) {
                return;
              }

              if (child.classList.contains('nfd-block-wrapper')) return;

              var wrapper = document.createElement('div');
              wrapper.className = 'nfd-block-wrapper';
              wrapper.dataset.blockIndex = blockIndex;

              child.parentNode.insertBefore(wrapper, child);
              wrapper.appendChild(child);
            });
          }

          // Called by the parent frame to inject or update content without reloading the iframe.
          // Animations are suppressed while calls arrive rapidly (streaming); 300 ms after the
          // last call the content is re-rendered without the flag so animations play once.
          window.nfdSetContent = function(html) {
            var root = document.getElementById('nfd-preview-root');
            if (!root) return;

            _lastHtml = html;

            // Suppress animations immediately — every rapid delta starts from opacity:0 otherwise.
            root.setAttribute('data-nfd-streaming', '');

            // Reset debounce: 300 ms after the last call, re-render without the flag
            // so entrance animations play on the settled final content.
            if (_streamingTimer) clearTimeout(_streamingTimer);
            _streamingTimer = setTimeout(function() {
              _streamingTimer = null;
              var r = document.getElementById('nfd-preview-root');
              if (!r) return;
              r.removeAttribute('data-nfd-streaming');
              _applyContent(r, _lastHtml);
            }, 300);

            _applyContent(root, html);
          };

          // Shared: set innerHTML and re-run all wrapping + animation observer setup.
          function _applyContent(root, html) {
            root.innerHTML = html;

            _wrapTextNodes(root);
            var containersToWrap = root.querySelectorAll('.wp-site-blocks, .entry-content, .wp-block-group, .wp-block-column, .wp-block-cover__inner-container, .wp-block-media-text__content, .wp-block-post-content');
            containersToWrap.forEach(function(container) { _wrapTextNodes(container); });
            _wrapChildren(root, '');

            // Reset scroll-triggered animation observer for new elements.
            if (_animObserver) _animObserver.disconnect();
            _animObserver = new IntersectionObserver(function(entries) {
              entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                  var delay = parseInt(entry.target.getAttribute('data-aos-delay') || '0', 10);
                  setTimeout(function() { entry.target.classList.add('aos-animate'); }, delay);
                  _animObserver.unobserve(entry.target);
                }
              });
            }, { threshold: 0 });
            root.querySelectorAll('[data-aos]').forEach(function(el) { _animObserver.observe(el); });
          }

          document.addEventListener('DOMContentLoaded', function() {
            document.addEventListener('click', function(e) {
              e.preventDefault();
              var wrapper = e.target.closest('.nfd-block-wrapper');

              document.querySelectorAll('.nfd-block-wrapper').forEach(function(el) { el.classList.remove('nfd-block-selected'); });

              if (wrapper) {
                wrapper.classList.add('nfd-block-selected');
                var tempWrapper = wrapper.cloneNode(true);
                tempWrapper.classList.remove('nfd-block-selected');

                tempWrapper.querySelectorAll('.nfd-block-wrapper').forEach(function(w) {
                  w.classList.remove('nfd-block-selected');
                });

                var html = tempWrapper.innerHTML;
                window.parent.postMessage({ type: 'NFD_BLOCK_SELECTED', index: wrapper.dataset.blockIndex, html: html }, '*');
              } else {
                window.parent.postMessage({ type: 'NFD_BLOCK_SELECTED', index: null, html: null }, '*');
              }
            });

            window.addEventListener('message', function(e) {
              if (e.data && e.data.type === 'NFD_CLEAR_SELECTION') {
                document.querySelectorAll('.nfd-block-wrapper').forEach(function(el) { el.classList.remove('nfd-block-selected'); });
              }
            });

            // Signal parent that the DOM is ready for content injection.
            // This fires before external resources (e.g. Google Fonts) finish loading,
            // enabling progressive content updates during streaming without waiting for load.
            window.parent.postMessage({ type: 'NFD_IFRAME_DOM_READY' }, '*');
          });
        <\/script>
      `;

      const useShell = Boolean( frontendShellHtml );

      let siteBase = '';
      try {
        siteBase = new URL( previewUrl ).origin + '/';
      } catch {
        // previewUrl might be relative; skip base tag
      }
      const baseTag = siteBase ? `<base href="${ siteBase }">` : '';

      // Shell has an empty #nfd-preview-root — content is injected after load via nfdSetContent.
      const fullHtml = useShell
        ? `<!DOCTYPE html><html><head><meta charset="utf-8">${ baseTag }${ headStyles }${ script }</head><body${ frontendBodyClass ? ` class="${ frontendBodyClass }"` : '' }>${ frontendShellHtml }</body></html>`
        : `<!DOCTYPE html><html><head><meta charset="utf-8">${ baseTag }${ headStyles }${ script }</head><body${ frontendBodyClass ? ` class="${ frontendBodyClass }"` : '' }><div id="nfd-preview-root"></div></body></html>`;

      const iframe = iframeRef.current;

      // Guard against double-firing: DOMContentLoaded postMessage arrives first (before
      // external resources finish), and the iframe load event follows later. Whichever
      // fires first sets iframeReadyRef and injects content; the second is a no-op.
      const onReady = () => {
        if ( iframeReadyRef.current ) return;
        iframeReadyRef.current = true;
        const html = pendingHtmlRef.current;
        if ( html && iframe.contentWindow ) {
          ( iframe.contentWindow as any ).nfdSetContent?.( html );
        }
      };

      // Listen for the iframe's DOMContentLoaded signal — fires before Google Fonts CDN
      // loads, so streaming deltas can be injected progressively without jitter.
      const onMessage = ( event: MessageEvent ) => {
        if ( event.data?.type === 'NFD_IFRAME_DOM_READY' && event.source === iframe.contentWindow ) {
          onReady();
        }
      };

      window.addEventListener( 'message', onMessage );
      // load is a fallback in case the postMessage is somehow missed.
      iframe.addEventListener( 'load', onReady );
      iframe.srcdoc = fullHtml;

      return () => {
        window.removeEventListener( 'message', onMessage );
        iframe.removeEventListener( 'load', onReady );
      };
  }, [ frontendBodyClass, frontendShellHtml, frontendStyles, previewStylesheets, previewUrl, !! previewHtml ] );
  // !!previewHtml (boolean) is included so Effect 1 re-runs when the iframe first mounts
  // (PreviewFrame renders the <iframe> only once previewHtml is truthy). It never changes
  // again during streaming, so this does not cause per-delta reloads.

  // Effect 2: In-place content update. Never rebuilds srcdoc, never reloads the iframe.
  // Throttled with rAF: cleanup cancels the previous pending frame so that rapid streaming
  // deltas coalesce into at most one DOM update per animation frame (~16 ms).
  // isStreaming is in deps so that when streaming ends, Effect 2 re-runs one final time
  // with streaming=false, triggering nfdSetContent to remove data-nfd-streaming and let
  // entrance animations play on the completed content.
  useEffect( () => {
    if ( ! previewHtml ) {
      return;
    }

    pendingHtmlRef.current = previewHtml;

    // Full HTML documents can't be injected into #nfd-preview-root — fall back to srcdoc.
    if ( previewHtml.includes( '<html' ) ) {
      if ( iframeRef.current ) {
        iframeRef.current.srcdoc = previewHtml;
      }
      return;
    }

    if ( ! iframeReadyRef.current || ! iframeRef.current?.contentWindow ) {
      // Iframe not ready yet — pendingHtmlRef.current will be consumed by onReady.
      return;
    }

    const win = iframeRef.current.contentWindow;
    const rafId = requestAnimationFrame( () => {
      // Use pendingHtmlRef so we always inject the latest delta, not the stale closure value.
      ( win as any ).nfdSetContent?.( pendingHtmlRef.current );
    } );

    // Cleanup cancels this frame if another delta arrives before the next paint.
    return () => cancelAnimationFrame( rafId );
  }, [ previewHtml ] );

  return { iframeRef };
};

export default usePreviewIframe;
