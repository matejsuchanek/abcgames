import os
import random
import re

import pymysql
import pywikibot

from pywikibot import link_regex as LINK_REGEX, textlib
from pywikibot.bot import CurrentPageBot, SingleSiteBot
from pywikibot.pagegenerators import (
    GeneratorFactory,
    PreloadingEntityGenerator,
)


class DescriptionsBot(CurrentPageBot, SingleSiteBot):

    def __init__(self, db, **kwargs):
        self.available_options.update({
            'min_words': 2,
            'min_chars': 10,
            'max_chars': 100,
        })
        super().__init__(**kwargs)
        self.db = db
        self.FORMATTING_REGEX = re.compile("('{5}|'{2,3})")
        self.regex = self.get_regex_for_title(r'[^\n\[\|\]{}<>]+')

    def get_regex_for_title(self, escaped_title):
        pattern = r'^\*+ *\[\[(%s)(?:\|[^][]+)?\]\]' % escaped_title
        pattern += r' *(?:\([^)]+\))?'
        pattern += '(?:,| [-–]) *(.*)$'
        return re.compile(pattern, re.M)

    @staticmethod
    def handle_link(match):
        text = match[2]
        if text:
            return text.lstrip('|').strip()
        else:
            return match['title'].strip()

    def validate_description(self, desc):
        return (
            desc != ''
            and len(desc.split()) >= self.opt.min_words
            and self.opt.min_chars <= len(desc) <= self.opt.max_chars)

    def parse_description(self, desc):
        desc = LINK_REGEX.sub(self.handle_link, desc)
        desc = self.FORMATTING_REGEX.sub('', desc).replace('&nbsp;', ' ')
        desc = re.sub(r' *\([^)]+\)$', '', desc.rstrip())
        desc = desc.partition(';')[0]
        desc = re.sub(r'^.*\) [-–] +', '', desc)
        desc = re.sub(r'^\([^)]+\),? +', '', desc)
        desc = re.sub(' {2,}', ' ', desc).strip()

        match = re.search(r'[^A-Z]\. [A-Z]', desc)
        if match:
            new_desc = desc[:match.start()+1].rstrip()
            if not new_desc.endswith(('sv', 'mj', 'tj', 'tzn', 'tzv', 'např')):
                desc = new_desc

        if desc.endswith(tuple(',:')):
            desc = desc[:-1].rstrip()

        if desc.endswith('.') \
           and not desc.endswith((' atd.', ' apod.', ' n. l.')) \
           and not re.search(r'\b[IVX]+\.$', desc) \
           and not re.search(r'\b[A-Z]\.[A-Z]\.$', desc):
            desc = desc[:-1].rstrip()

        if self.site.lang == 'en' \
           and desc.lower().startswith(('a ', 'an ', 'the ')):
            desc = desc.partition(' ')[2]

        return desc

    def get_pages_with_descriptions(self, text):
        tags = {'category', 'comment', 'file', 'header', 'hyperlink',
                'interwiki', 'nowiki', 'pre', 'ref', 'syntaxhighlight',
                'template', 'timeline'}
        text = textlib.removeDisabledParts(text, tags, site=self.site)
        data = {}
        for match in self.regex.finditer(text):
            title, desc = match.groups()
            page = pywikibot.Page(self.site, title)
            data[page] = self.parse_description(desc)
        return data

    def treat_page(self):
        page = self.current_page
        descriptions = self.get_pages_with_descriptions(page.text)
        add = False
        for item in PreloadingEntityGenerator(descriptions.keys()):
            if self.site.lang in item.descriptions:
                continue
            target = pywikibot.Page(item.sitelinks[self.site])
            desc = descriptions.get(target)
            if not self.validate_description(desc):
                continue
            with self.db.cursor() as cur:
                cur.execute(
                    'SELECT description FROM descriptions'
                    ' WHERE item = %s AND lang = %s',
                    (item.id, self.site.lang))
                if desc in map(lambda row: row[0], cur.fetchall()):
                    continue
                cur.execute(
                    'INSERT INTO descriptions (item, lang, random, description)'
                    ' VALUES (%s, %s, %s, %s)',
                    (item.id, self.site.lang, random.randrange(2**31), desc))
                add = True
        if add:
            self.db.commit()


def main(*args):
    options = {}
    local_args = pywikibot.handle_args(args)
    site = pywikibot.Site()
    genFactory = GeneratorFactory(site=site)
    for arg in genFactory.handle_args(local_args):
        if arg.startswith('-'):
            arg, sep, value = arg.partition(':')
            if value != '':
                options[arg[1:]] = int(value) if value.isdigit() else value
            else:
                options[arg[1:]] = True

    db = pymysql.connect(
        database='s53805__data',
        host='tools.db.svc.eqiad.wmflabs',
        read_default_file=os.path.expanduser('~/replica.my.cnf'),
        charset='utf8mb4',
    )
    generator = genFactory.getCombinedGenerator(preload=True)
    bot = DescriptionsBot(db, generator=generator, site=site, **options)
    bot.run()


if __name__ == '__main__':
    main()
