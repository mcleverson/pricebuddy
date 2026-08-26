FROM jez500/seleniumbase-scrapper:latest

WORKDIR /SeleniumBase

COPY apply_article_patch.py /tmp/apply_article_patch.py
RUN python3 /tmp/apply_article_patch.py \
    && rm /tmp/apply_article_patch.py

COPY tests/test_article_options.py /SeleniumBase/api/tests/test_article_options.py
