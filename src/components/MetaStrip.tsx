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
      <div className="ai-meta-strip__section">
        <span className="ai-meta-strip__label">Page Title</span>
        <input
          type="text"
          className="ai-meta-strip__input"
          value={ title }
          onChange={ ( event ) => onChangeTitle( event.target.value ) }
          placeholder="Untitled"
        />
      </div>

      <div className="ai-meta-strip__section">
        <span className="ai-meta-strip__label">Excerpt</span>
        <textarea
          className="ai-meta-strip__textarea"
          value={ excerpt }
          onChange={ ( event ) => onChangeExcerpt( event.target.value ) }
          placeholder="Short summary..."
          rows={ 2 }
        />
      </div>

{ supportsThumbnail && (
        <div className="ai-meta-strip__section ai-meta-strip__section--image">
          <div className="ai-meta-strip__thumb">
            { featuredImageUrl ? (
              <img src={ featuredImageUrl } alt="" />
            ) : (
              <span className="ai-meta-strip__thumb-placeholder">No image</span>
            ) }
          </div>
          <button
            type="button"
            className="ai-meta-strip__change-link"
            onClick={ onPickImage }
            disabled={ ! canUseMedia }
          >
            Change Hero
            { ! canUseMedia && (
              <span className="ai-meta-strip__hint">Unavailable</span>
            ) }
          </button>
          { ( featuredImageUrl || ( featuredMediaId && featuredMediaId > 0 ) ) ? (
            <button
              type="button"
              className="ai-meta-strip__remove-link"
              onClick={ onRemoveImage }
            >
              Remove
            </button>
          ) : null }
        </div>
      ) }
    </div>
  );
};

export default MetaStrip;
