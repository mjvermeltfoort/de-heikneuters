import { XMLParser } from 'fast-xml-parser';

const CHANNEL_ID = 'UCTRbf6GaQNeR43AiWMnprlg';
const FEED_URL = `https://www.youtube.com/feeds/videos.xml?channel_id=${CHANNEL_ID}`;

export type YouTubeVideo = {
  id: string;
  title: string;
  published: Date;
  url: string;
  thumbnail: string;
};

export async function getLatestYouTubeVideos(limit = 6): Promise<YouTubeVideo[]> {
  try {
    const response = await fetch(FEED_URL, {
      headers: {
        'User-Agent': 'de-heikneuters.nl build',
      },
      signal: AbortSignal.timeout(10_000),
    });

    if (!response.ok) {
      console.warn(`YouTube feed failed (${response.status})`);
      return [];
    }

    const xml = await response.text();
    const parser = new XMLParser({
      ignoreAttributes: false,
      attributeNamePrefix: '@_',
    });

    const parsed = parser.parse(xml);
    const entries = parsed?.feed?.entry;
    const list = Array.isArray(entries) ? entries : entries ? [entries] : [];

    return list
      .map((entry: any) => {
        const id = String(entry?.['yt:videoId'] ?? '').trim();
        const title = String(entry?.title ?? '').trim();
        const published = new Date(String(entry?.published ?? ''));
        const url = String(entry?.link?.['@_href'] ?? (id ? `https://www.youtube.com/watch?v=${id}` : ''));

        if (!id || !title || Number.isNaN(published.getTime())) {
          return null;
        }

        return {
          id,
          title,
          published,
          url,
          thumbnail: `https://i.ytimg.com/vi/${id}/hqdefault.jpg`,
        } satisfies YouTubeVideo;
      })
      .filter((video: YouTubeVideo | null): video is YouTubeVideo => Boolean(video))
      .slice(0, limit);
  } catch (error) {
    console.warn('YouTube feed failed:', error);
    return [];
  }
}

export function formatYouTubeDate(date: Date): string {
  return new Intl.DateTimeFormat('nl-NL', {
    timeZone: 'Europe/Amsterdam',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(date);
}
