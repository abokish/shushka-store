"""
fix_recipe_hebrew.py
מושך את טיוטות המתכונים הקיימות, מתקן שגיאות עברית עם Sonnet, ומעדכן ב-WordPress.
"""

import urllib.request, json, base64, os, time

def _load_env():
    env = {}
    p = os.path.join(os.path.dirname(os.path.abspath(__file__)), '.env')
    with open(p, encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if line and not line.startswith('#') and '=' in line:
                k, v = line.split('=', 1)
                env[k.strip()] = v.strip()
    return env

_ENV       = _load_env()
ANT_KEY    = _ENV['ANTHROPIC_API_KEY']
WP_BASE    = _ENV.get('WC_URL', 'https://shushka.co.il') + '/wp-json'
WP_AUTH    = base64.b64encode(f"{_ENV['WP_ADMIN_USERNAME']}:{_ENV['WP_ADMIN_PASSWORD']}".encode()).decode()
WP_HEADERS = {'Authorization': f'Basic {WP_AUTH}', 'Content-Type': 'application/json'}

def wp_request(path, method='GET', body=None):
    url  = f"{WP_BASE}/{path}"
    data = json.dumps(body, ensure_ascii=False).encode('utf-8') if body else None
    req  = urllib.request.Request(url, data=data, headers=WP_HEADERS, method=method)
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read())

def claude_sonnet(prompt):
    data = json.dumps({
        'model': 'claude-sonnet-4-6',
        'max_tokens': 2000,
        'messages': [{'role': 'user', 'content': prompt}]
    }).encode()
    req = urllib.request.Request(
        'https://api.anthropic.com/v1/messages', data=data,
        headers={'x-api-key': ANT_KEY, 'anthropic-version': '2023-06-01', 'content-type': 'application/json'},
        method='POST'
    )
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read())['content'][0]['text'].strip()

def review_hebrew(instructions_html, title):
    prompt = f"""תקן שגיאות עברית בהוראות ההכנה של המתכון "{title}".

{instructions_html}

בדוק ותקן:
- מילים שאינן קיימות בעברית (כמו "חומצמים", "קורמט", "גששו", "קורמט")
- מילים עם רווח שגוי באמצע (כמו "הע וגה" → "העוגה")
- ניסוח לא טבעי בעברית
- שגיאות דקדוק

החזר JSON בלבד ללא markdown:
{{"instructions_html": "<ol><li>...</li></ol>"}}"""

    text = claude_sonnet(prompt)
    if '```' in text:
        text = text.split('```')[1]
        if text.startswith('json'): text = text[4:]
    return json.loads(text.strip())['instructions_html']

def main():
    print('📖 מושך מתכונים (טיוטות + מפורסמים)...')
    posts = wp_request('wp/v2/shpk_recipe?status=draft,publish&per_page=20')
    print(f'נמצאו {len(posts)} מתכונים\n')

    done = fail = 0
    for i, post in enumerate(posts, 1):
        title = post['title']['rendered']
        post_id = post['id']
        original_html = post['content']['rendered']

        print(f'[{i}/{len(posts)}] {title}')
        try:
            fixed_html = review_hebrew(original_html, title)
            wp_request(f'wp/v2/shpk_recipe/{post_id}', 'POST', {'content': fixed_html})
            print(f'  ✓ תוקן')
            done += 1
            time.sleep(1)
        except Exception as e:
            print(f'  ✗ שגיאה: {e}')
            fail += 1
            time.sleep(2)

    print(f'\n{"="*50}')
    print(f'✓ תוקנו: {done}  |  שגיאות: {fail}')

if __name__ == '__main__':
    main()
