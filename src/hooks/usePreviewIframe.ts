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
  previewStylesheets?: PreviewStylesheets,
  isStreaming: boolean = false,
  externalIframeRef?: RefObject<HTMLIFrameElement>,
  motionCss: string = ''
): UsePreviewIframeResult => {
  const localIframeRef = useRef<HTMLIFrameElement>( null );
  // Allow the caller to own the ref so it can be shared with other hooks
  // (e.g. useAiConversation) without a declaration-order cycle.
  const iframeRef = externalIframeRef ?? localIframeRef;
  const [ frontendStyles, setFrontendStyles ] = useState( '' );
  const [ frontendBodyClass, setFrontendBodyClass ] = useState( '' );
  const [ frontendShellHtml, setFrontendShellHtml ] = useState( '' );

  // True once the current srcdoc has finished loading and nfdSetContent is callable.
  const iframeReadyRef = useRef( false );
  // Holds the latest previewHtml so the onLoad handler can inject it after shell init.
  const pendingHtmlRef = useRef<string | null>( null );
  // Mirrors isStreaming so the ready-handler (Effect 1, which excludes content
  // deps to avoid reloads) can read the current streaming state when it injects.
  const isStreamingRef = useRef( isStreaming );
  isStreamingRef.current = isStreaming;

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
          .nfd-block-wrapper.nfd-block-hover::before { outline-color: #007cba; background: rgba(0, 124, 186, 0.05); }
          .nfd-block-wrapper.nfd-block-selected::before { outline: 3px solid #d63638; outline-offset: -3px; background: rgba(214, 54, 56, 0.05); z-index: 101; }
          .nfd-block-wrapper a, .nfd-block-wrapper button { pointer-events: none; }

          /* Flex layout fallback: WordPress's layout-support flex styles arrive via the
             async global/frontend stylesheet, so on the first paint a wp-block-buttons row
             can render without flex and (with the scale-in transform) overlap. Pin the
             buttons row to flex here so it never depends on that timing. */
          #nfd-preview-root .wp-block-buttons.is-layout-flex,
          #nfd-preview-root .wp-block-buttons-is-layout-flex {
            display: flex; flex-wrap: wrap; gap: 0.5em; align-items: center;
          }

          /* Override ALL transitions and animations during streaming.
             The AIPageDesigner.php enqueues animation rules via
             wp_add_inline_style('wp-block-library', ...) which end up in
             frontendStyles. This blanket * rule also suppresses
             .nfd-block-wrapper::before { transition: all 0.2s } which fires
             on every DOM replacement. These MUST NOT fire during streaming. */
          #nfd-preview-root[data-nfd-streaming] * {
            transition: none !important;
            animation: none !important;
          }
          #nfd-preview-root[data-nfd-streaming] .fade-in,
          #nfd-preview-root[data-nfd-streaming] .slide-up,
          #nfd-preview-root[data-nfd-streaming] .bounce-in,
          #nfd-preview-root[data-nfd-streaming] .scale-in,
          #nfd-preview-root[data-nfd-streaming] .fade-in-delay-1,
          #nfd-preview-root[data-nfd-streaming] .fade-in-delay-2,
          #nfd-preview-root[data-nfd-streaming] .fade-in-delay-3,
          #nfd-preview-root[data-nfd-streaming] [data-aos],
          #nfd-preview-root[data-nfd-streaming] .pulse-hover,
          #nfd-preview-root[data-nfd-streaming] .glow-hover,
          #nfd-preview-root[data-nfd-streaming] .card-hover-lift {
            opacity: 1 !important;
            transform: none !important;
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
          // Module-level state for nfdSetContent.
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

          function _isTransparentContainer(el) {
            return el.classList.contains('wp-site-blocks') ||
              el.classList.contains('entry-content') ||
              el.classList.contains('wp-block-group') ||
              el.classList.contains('wp-block-column') ||
              el.classList.contains('wp-block-columns') ||
              el.classList.contains('wp-block-cover__inner-container') ||
              el.classList.contains('wp-block-media-text__content') ||
              el.classList.contains('wp-block-post-content');
          }

          function _wrapChildren(parent, parentPath) {
            Array.from(parent.children).forEach(function(child, index) {
              if (child.tagName === 'SCRIPT' || child.tagName === 'STYLE' || child.classList.contains('nfd-block-wrapper')) return;
              // Skip cover background overlays — they are absolutely positioned and must not be wrapped.
              if (child.classList.contains('wp-block-cover__background')) return;

              var blockIndex = parentPath ? parentPath + '-' + index : index.toString();

              if (_isTransparentContainer(child)) {
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

              // After wrapping, recurse into any transparent containers that are direct children
              // of this block (e.g. wp-block-cover__inner-container inside a cover block).
              // This exposes sub-blocks for selection without wrapping layout-critical elements.
              Array.from(child.children).forEach(function(grandchild, gci) {
                if (_isTransparentContainer(grandchild)) {
                  _wrapChildren(grandchild, blockIndex + '-' + gci);
                }
              });
            });
          }

          function _toTitleCase(value) {
            if (!value) return '';
            return value
              .replace(/[-_]+/g, ' ')
              .trim()
              .replace(/\b\w/g, function(ch) { return ch.toUpperCase(); });
          }

          function _resolveBlockLabel(wrapper) {
            if (!wrapper || !wrapper.firstElementChild) return 'Section';
            var el = wrapper.firstElementChild;
            var classNames = Array.from(el.classList || []);
            var wpBlockClass = classNames.find(function(cls) { return cls.indexOf('wp-block-') === 0; });

            if (wpBlockClass) {
              var raw = wpBlockClass.replace(/^wp-block-/, '');
              if (raw === 'heading') return 'Heading';
              if (raw === 'paragraph') return 'Paragraph';
              if (raw === 'image') return 'Image';
              if (raw === 'cover') return 'Cover';
              if (raw === 'columns') return 'Columns';
              if (raw === 'column') return 'Column';
              if (raw === 'buttons') return 'Buttons';
              if (raw === 'button') return 'Button';
              if (raw === 'list') return 'List';
              if (raw === 'quote') return 'Quote';
              if (raw === 'separator') return 'Separator';
              if (raw === 'spacer') return 'Spacer';
              if (raw === 'group') return 'Group';
              return _toTitleCase(raw);
            }

            var tag = (el.tagName || '').toLowerCase();
            if (tag === 'h1' || tag === 'h2' || tag === 'h3' || tag === 'h4' || tag === 'h5' || tag === 'h6') return 'Heading';
            if (tag === 'p') return 'Paragraph';
            if (tag === 'img' || tag === 'figure') return 'Image';
            if (tag === 'ul' || tag === 'ol') return 'List';
            if (tag === 'blockquote') return 'Quote';
            if (tag === 'button' || tag === 'a') return 'Button';

            return 'Section';
          }

          // Inject animation CSS (once) + (re)attach the IntersectionObserver.
          // Called only on finalize (stream complete / non-streamed update), never
          // during streaming — so no animation can fire while deltas are arriving.
          // The CSS itself comes from the parent (AIPageDesigner::get_motion_css(),
          // scoped to #nfd-preview-root) rather than being duplicated here — it's
          // the same source enqueue_frontend_animations() uses for the published
          // page, so a motion class can never animate in the editor and silently
          // do nothing once published (or vice versa).
          function _enableAnimations() {
            if (!document.getElementById('nfd-anim-style')) {
            var style = document.createElement('style');
            style.id = 'nfd-anim-style';
            style.textContent = ${ JSON.stringify( motionCss ) };
            document.head.appendChild(style);
            }

            // (Re)attach the IntersectionObserver on every finalize so data-aos
            // elements from a fresh generation get observed even when the style
            // tag already exists from a prior run.
            var observer = new IntersectionObserver(function(entries) {
              entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                  var delay = parseInt(entry.target.getAttribute('data-aos-delay') || '0', 10);
                  setTimeout(function() { entry.target.classList.add('aos-animate'); }, delay);
                  observer.unobserve(entry.target);
                }
              });
            }, { threshold: 0 });
            document.querySelectorAll('#nfd-preview-root [data-aos]').forEach(function(el) { observer.observe(el); });
          }

          // Called by the parent frame to inject or update content without reloading the iframe.
          // The parent passes finalize=true only when streaming has actually ended (driven by
          // the conversation's isLoading flag), not a time-based guess — so animations fire
          // exactly once on the settled result regardless of how chunks are delivered.
          //   finalize falsy  -> streaming delta: set the streaming flag and strip entrance
          //                      animation classes so nothing animates while content churns.
          //   finalize true   -> stream complete (or a non-streamed update): clear the flag,
          //                      enable animations, and render once with classes intact.
          window.nfdSetContent = function(html, finalize) {
            var root = document.getElementById('nfd-preview-root');
            if (!root) return;

            _lastHtml = html;

            if (finalize) {
              root.removeAttribute('data-nfd-streaming');
              _enableAnimations();
              _applyContent(root, html, true);
              return;
            }

            root.setAttribute('data-nfd-streaming', '');
            _applyContent(root, html);
          };

          // Shared: set innerHTML and re-run all wrapping.
          // During streaming (data-nfd-streaming is set), animation classes
          // are stripped from the live DOM synchronously after innerHTML —
          // the browser never sees them, so no CSS can animate. A truthy
          // third argument skips stripping for the final debounce render.
          function _applyContent(root, html, skipAnimStrip) {
            root.innerHTML = html;

            if (!skipAnimStrip && root.hasAttribute('data-nfd-streaming')) {
              var animClasses = ['fade-in', 'slide-up', 'bounce-in', 'scale-in',
                'fade-in-delay-1', 'fade-in-delay-2', 'fade-in-delay-3',
                'pulse-hover', 'glow-hover', 'card-hover-lift'];
              for (var i = 0; i < animClasses.length; i++) {
                var els = root.querySelectorAll('.' + animClasses[i]);
                for (var j = 0; j < els.length; j++) {
                  els[j].classList.remove(animClasses[i]);
                }
              }
            }

            _wrapTextNodes(root);
            var containersToWrap = root.querySelectorAll('.wp-site-blocks, .entry-content, .wp-block-group, .wp-block-column, .wp-block-cover__inner-container, .wp-block-media-text__content, .wp-block-post-content');
            containersToWrap.forEach(function(container) { _wrapTextNodes(container); });
            _wrapChildren(root, '');
          }

          document.addEventListener('DOMContentLoaded', function() {
            document.addEventListener('mouseover', function(e) {
              document.querySelectorAll('.nfd-block-wrapper.nfd-block-hover').forEach(function(el) { el.classList.remove('nfd-block-hover'); });
              var wrapper = e.target.closest('.nfd-block-wrapper');
              if (wrapper) wrapper.classList.add('nfd-block-hover');
            });

            document.addEventListener('mouseleave', function() {
              document.querySelectorAll('.nfd-block-wrapper.nfd-block-hover').forEach(function(el) { el.classList.remove('nfd-block-hover'); });
            });

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
                var blockLabel = _resolveBlockLabel(wrapper);
                window.parent.postMessage({ type: 'NFD_BLOCK_SELECTED', index: wrapper.dataset.blockIndex, html: html, blockLabel: blockLabel }, '*');
              } else {
                window.parent.postMessage({ type: 'NFD_BLOCK_SELECTED', index: null, html: null, blockLabel: null }, '*');
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
          ( iframe.contentWindow as any ).nfdSetContent?.( html, ! isStreamingRef.current );
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
  }, [ frontendBodyClass, frontendShellHtml, frontendStyles, previewStylesheets, previewUrl, !! previewHtml, motionCss ] );
  // !!previewHtml (boolean) is included so Effect 1 re-runs when the iframe first mounts
  // (PreviewFrame renders the <iframe> only once previewHtml is truthy). It never changes
  // again during streaming, so this does not cause per-delta reloads.

  // Effect 2: In-place content update. Never rebuilds srcdoc, never reloads the iframe.
  // Throttled with rAF: cleanup cancels the previous pending frame so that rapid streaming
  // deltas coalesce into at most one DOM update per animation frame (~16 ms).
  // isStreaming is in deps so that when streaming ends, Effect 2 re-runs one final time
  // with finalize=true, triggering nfdSetContent to remove data-nfd-streaming, enable
  // animations, and play entrance animations exactly once on the completed content.
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
    // finalize only when the stream is no longer active — animations play once here.
    const finalize = ! isStreaming;
    const rafId = requestAnimationFrame( () => {
      // Use pendingHtmlRef so we always inject the latest delta, not the stale closure value.
      ( win as any ).nfdSetContent?.( pendingHtmlRef.current, finalize );
    } );

    // Cleanup cancels this frame if another delta arrives before the next paint.
    return () => cancelAnimationFrame( rafId );
  }, [ previewHtml, isStreaming ] );

  return { iframeRef };
};

export default usePreviewIframe;
