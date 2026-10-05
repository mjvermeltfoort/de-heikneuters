export type FacebookPost = {
  id: string;
  message: string;
  createdTime: Date;
  permalinkUrl: string;
  imageUrl: string | null;
};

const DEFAULT_GRAPH_VERSION = 'v24.0';

export async function getLatestFacebookPosts(limit = 3): Promise<FacebookPost[]> {
  const pageId = process.env.FACEBOOK_PAGE_ID?.trim();
  const accessToken = process.env.FACEBOOK_PAGE_ACCESS_TOKEN?.trim();
  const graphVersion = process.env.FACEBOOK_GRAPH_VERSION?.trim() || DEFAULT_GRAPH_VERSION;

  if (!pageId || !accessToken) {
    console.warn('Facebook feed skipped: FACEBOOK_PAGE_ID or FACEBOOK_PAGE_ACCESS_TOKEN is missing.');
    return [];
  }

  const fields = [
    'id',
    'message',
    'created_time',
    'permalink_url',
    'full_picture',
  ].join(',');

  const url = new URL(`https://graph.facebook.com/${graphVersion}/${pageId}/posts`);
  url.searchParams.set('fields', fields);
  url.searchParams.set('limit', String(Math.max(limit, 6)));
  url.searchParams.set('access_token', accessToken);

  try {
    const response = await fetch(url, {
      headers: {
        'User-Agent': 'de-heikneuters.nl build',
      },
      signal: AbortSignal.timeout(10_000),
    });

    if (!response.ok) {
      const errorText = await response.text();
      console.warn(`Facebook feed failed (${response.status}): ${errorText.slice(0, 300)}`);
      return [];
    }

    const payload = await response.json() as {
      data?: Array<{
        id?: string;
        message?: string;
        created_time?: string;
        permalink_url?: string;
        full_picture?: string;
      }>;
    };

    return (payload.data ?? [])
      .filter((post) => Boolean(post.id && post.message && post.permalink_url && post.created_time))
      .slice(0, limit)
      .map((post) => ({
        id: String(post.id),
        message: String(post.message),
        createdTime: new Date(String(post.created_time)),
        permalinkUrl: String(post.permalink_url),
        imageUrl: post.full_picture ? String(post.full_picture) : null,
      }));
  } catch (error) {
    console.warn('Facebook feed failed:', error);
    return [];
  }
}

export function formatFacebookDate(date: Date): string {
  return new Intl.DateTimeFormat('nl-NL', {
    timeZone: 'Europe/Amsterdam',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(date);
}

export function facebookExcerpt(message: string, maxLength = 220): string {
  const normalized = message.replace(/\s+/g, ' ').trim();

  if (normalized.length <= maxLength) {
    return normalized;
  }

  return normalized.slice(0, maxLength).replace(/\s+\S*$/, '') + '…';
}
