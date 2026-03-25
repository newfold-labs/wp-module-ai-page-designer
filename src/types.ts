export type Message = {
  role: 'user' | 'assistant';
  content: string;
  link?: string;
};

export type WPItem = {
  id: number;
  title: { rendered: string };
  content?: { rendered: string; raw?: string };
  excerpt?: { rendered?: string; raw?: string };
  featured_media?: number;
  featured_image_url?: string;
  supports_thumbnail?: boolean;
  status: string;
  link: string;
  type: string;
};

export type PublishStatus = { type: 'success' | 'error'; message: string } | null;

export type HistoryEntry = {
  id: string;
  html: string;
  label: string;
  timestamp: string;
  publishTitle?: string;
};
