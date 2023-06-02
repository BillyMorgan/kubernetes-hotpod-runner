FROM php

RUN curl -LO "https://dl.k8s.io/release/$(curl -L -s https://dl.k8s.io/release/stable.txt)/bin/linux/amd64/kubectl" && \
    curl -LO "https://dl.k8s.io/$(curl -L -s https://dl.k8s.io/release/stable.txt)/bin/linux/amd64/kubectl.sha256" && \
    echo "$(cat kubectl.sha256) kubectl" | sha256sum --check && \
    install -o root -g root -m 0755 kubectl /usr/local/bin/kubectl

RUN kubectl version --client

RUN mkdir /app
ADD vendor /app/vendor
ADD src /app/src
ADD bin /app/bin
ENTRYPOINT ["/app/bin/docker-entrypoint","--"]