import React from 'react';

type Props = {
  visible: boolean;
  title: string;
  excerpt: string;
  featuredImageUrl: string | null;
  featuredMediaId: number | null;
  supportsThumbnail: boolean;
  canUseMedia: boolean;
  onChangeTitle: (value: string) => void;
  onChangeExcerpt: (value: string) => void;
  onPickImage: () => void;
  onRemoveImage: () => void;
};

const MetaStrip = ( {
  visible,
  title,
  excerpt,
  featuredImageUrl,
  featuredMediaId,
  supportsThumbnail,
  canUseMedia,
  onChangeTitle,
  onChangeExcerpt,
  onPickImage,
  onRemoveImage,
}: Props ) => {
  if ( ! visible ) {
    return null;
  }

  return (
    <div className="ai-meta-strip">
      <div className="ai-meta-strip__field">
        <label htmlFor="ai-meta-title">Title</label>
        <input
          id="ai-meta-title"
          type="text"
          value={ title }
          onChange={ ( event ) => onChangeTitle( event.target.value ) }
          placeholder="Post title"
        />
      </div>

      <div className="ai-meta-strip__field ai-meta-strip__field--excerpt">
        <label htmlFor="ai-meta-excerpt">Excerpt</label>
        <textarea
          id="ai-meta-excerpt"
          value={ excerpt }
          onChange={ ( event ) => onChangeExcerpt( event.target.value ) }
          placeholder="Short summary..."
          rows={ 3 }
        />
      </div>

      <div className="ai-meta-strip__field ai-meta-strip__field--image">
        <label>Featured image</label>
        { supportsThumbnail ? (
          <>
            <div className="ai-meta-strip__image">
              { featuredImageUrl ? (
                <img src={ featuredImageUrl } alt="" />
              ) : (
                <div className="ai-meta-strip__image-placeholder">
                  No image
                </div>
              ) }
            </div>
            <div className="ai-meta-strip__actions">
              <button
                type="button"
                onClick={ onPickImage }
                disabled={ ! canUseMedia }
                className="ai-meta-strip__button"
              >
                Change image
              </button>
              <button
                type="button"
                onClick={ onRemoveImage }
                disabled={ ! featuredMediaId }
                className="ai-meta-strip__button ai-meta-strip__button--secondary"
              >
                Remove
              </button>
            </div>
            { ! canUseMedia && (
              <p className="ai-meta-strip__hint">Media picker unavailable.</p>
            ) }
          </>
        ) : (
          <p className="ai-meta-strip__hint">Featured images are not supported for this type.</p>
        ) }
      </div>
    </div>
  );
};

export default MetaStrip;
